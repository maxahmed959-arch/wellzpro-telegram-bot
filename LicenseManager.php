<?php

declare(strict_types=1);

namespace Wellz;

/**
 * إدارة التراخيص — توليد، تعطيل، حذف، وحالة الحساب.
 */
final class LicenseManager
{
    public function __construct(
        private FirebaseClient $firebase,
        private array $config,
        private string $dataDir,
        private ?AuditLogger $audit = null,
    ) {}

    public function isReady(): bool
    {
        return $this->firebase->isConfigured();
    }

    /** @return array{ok: string[], failed: int, label: string} */
    public function generateForPlan(string $plan, int $count, int $actorId, ?string $username = null): array
    {
        $plans = $this->config['license_plans'] ?? [];
        if (! isset($plans[$plan])) {
            throw new \InvalidArgumentException("خطة غير معروفة: {$plan}");
        }
        $count = max(1, min(50, $count));
        $ok = [];
        $failed = 0;
        for ($i = 0; $i < $count; $i++) {
            $code = $this->generateUniqueCode();
            $payload = $this->buildPayload($plan);
            if ($this->firebase->putLicense($code, $payload)) {
                $this->storeLocalStock($code, $plan);
                $ok[] = $code;
                $this->audit?->log('license.generate', $actorId, $username, ['code' => $code, 'plan' => $plan]);
            } else {
                $failed++;
            }
        }

        return [
            'ok' => $ok,
            'failed' => $failed,
            'label' => (string) ($plans[$plan]['label'] ?? $plan),
        ];
    }

    public function disable(string $code, int $actorId, ?string $username = null): bool
    {
        if ($this->firebase->getLicense($code) === null) {
            return false;
        }
        $ok = $this->firebase->patchLicense($code, [
            'isActive' => false,
            'status' => 'disabled',
        ]);
        if ($ok) {
            $this->audit?->log('license.disable', $actorId, $username, ['code' => strtoupper($code)]);
        }

        return $ok;
    }

    public function delete(string $code, int $actorId, ?string $username = null): bool
    {
        $row = $this->firebase->getLicense($code);
        if ($row === null) {
            return false;
        }
        $deviceId = trim((string) ($row['deviceId'] ?? ''));
        if ($deviceId !== '') {
            throw new \RuntimeException('يمكن حذف الرموز غير المُفعّلة فقط — استخدم التعطيل للمُفعّلة');
        }
        $ok = $this->firebase->deleteLicense($code);
        if ($ok) {
            $path = $this->localCodePath($code);
            if (is_file($path)) {
                @unlink($path);
            }
            $this->audit?->log('license.delete', $actorId, $username, ['code' => strtoupper($code)]);
        }

        return $ok;
    }

    public function assignFromStock(string $orderId): ?string
    {
        foreach (glob($this->dataDir.'/codes/*.json') ?: [] as $file) {
            $item = json_decode((string) file_get_contents($file), true);
            if (! is_array($item) || ($item['status'] ?? '') !== 'available') {
                continue;
            }
            $code = trim((string) ($item['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $item['status'] = 'assigned';
            $item['assigned_at'] = date('c');
            $item['order_id'] = $orderId;
            file_put_contents($file, json_encode($item, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $code;
        }

        return null;
    }

    /** @return list<string> */
    public function availableLocalCodes(): array
    {
        $codes = [];
        foreach (glob($this->dataDir.'/codes/*.json') ?: [] as $file) {
            $item = json_decode((string) file_get_contents($file), true);
            if (! is_array($item) || ($item['status'] ?? '') !== 'available') {
                continue;
            }
            $code = trim((string) ($item['code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        sort($codes);

        return $codes;
    }

    public function generateUniqueCode(): string
    {
        $prefix = preg_replace('/[^A-Z0-9]+/u', '', strtoupper((string) ($this->config['license_prefix'] ?? 'WELLZ')));
        if ($prefix === '') {
            $prefix = 'WELLZ';
        }
        do {
            $code = sprintf(
                '%s-%s-%s',
                $prefix,
                strtoupper(bin2hex(random_bytes(2))),
                strtoupper(bin2hex(random_bytes(2)))
            );
        } while ($this->localCodeExists($code) || $this->firebase->licenseExists($code));

        return $code;
    }

    /** @return array<string, mixed> */
    public function buildPayload(string $plan): array
    {
        $cfg = ($this->config['license_plans'] ?? [])[$plan] ?? [];
        $isLifetime = (bool) ($cfg['lifetime'] ?? false);
        $days = (int) ($cfg['days'] ?? 0);
        $hours = (int) ($cfg['hours'] ?? 0);

        if ($isLifetime) {
            $expiresAt = 'lifetime';
            $days = 0;
            $hours = 0;
        } elseif ($hours > 0) {
            $expiresAt = date('c', strtotime("+{$hours} hours"));
            $days = 0;
        } else {
            if ($days <= 0) {
                $days = 30;
            }
            $expiresAt = date('Y-m-d', strtotime("+{$days} days"));
            $hours = 0;
        }

        return [
            'isActive' => true,
            'duration_days' => $days,
            'duration_hours' => $hours,
            'expires_at' => $expiresAt,
            'deviceId' => '',
            'plan' => $plan,
            'status' => 'pending',
            'created_at' => date('c'),
            'created_by' => 'telegram-admin',
        ];
    }

    /** @param array<string, mixed> $data */
    public static function normalizeRow(string $key, array $data): array
    {
        $deviceId = trim((string) ($data['deviceId'] ?? ''));
        $expiresAtRaw = (string) ($data['expires_at'] ?? '-');
        $createdAtRaw = (string) ($data['created_at'] ?? '');
        $isActive = filter_var($data['isActive'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $status = (string) ($data['status'] ?? 'pending');

        $computed = $status;
        if (! $isActive) {
            $computed = 'disabled';
        } elseif ($deviceId === '') {
            $computed = self::isExpired($expiresAtRaw) ? 'expired' : 'pending';
        } elseif (self::isExpired($expiresAtRaw)) {
            $computed = 'expired';
        } else {
            $computed = 'active';
        }

        return [
            'key' => strtoupper($key),
            'isActive' => $isActive,
            'duration_days' => (int) ($data['duration_days'] ?? 0),
            'duration_hours' => (int) ($data['duration_hours'] ?? 0),
            'expires_at' => $expiresAtRaw,
            'expires_display' => self::formatExpires($expiresAtRaw),
            'deviceId' => $deviceId,
            'plan' => (string) ($data['plan'] ?? ''),
            'status' => $status,
            'computed_status' => $computed,
            'created_at_raw' => $createdAtRaw,
            'created_at' => self::formatDate($createdAtRaw),
            'activated_at' => (string) ($data['activated_at'] ?? ''),
            'created_by' => (string) ($data['created_by'] ?? ''),
        ];
    }

    private function storeLocalStock(string $code, string $plan): void
    {
        $dir = $this->dataDir.'/codes';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->localCodePath($code), json_encode([
            'code' => $code,
            'plan' => $plan,
            'status' => 'available',
            'created_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function localCodePath(string $code): string
    {
        return $this->dataDir.'/codes/'.preg_replace('/[^A-Z0-9\-]+/u', '_', strtoupper($code)).'.json';
    }

    private function localCodeExists(string $code): bool
    {
        return is_file($this->localCodePath($code));
    }

    private static function isExpired(string $expiresAt): bool
    {
        if (strtolower(trim($expiresAt)) === 'lifetime' || $expiresAt === '' || $expiresAt === '-') {
            return false;
        }
        try {
            $ts = strtotime($expiresAt);
            if ($ts === false) {
                return false;
            }
            if (str_contains($expiresAt, 'T') || preg_match('/\d{1,2}:\d{2}/', $expiresAt)) {
                return $ts < time();
            }

            return $ts < strtotime('today');
        } catch (\Throwable) {
            return false;
        }
    }

    private static function formatExpires(string $value): string
    {
        if (strtolower(trim($value)) === 'lifetime') {
            return 'مدى الحياة';
        }
        $ts = strtotime($value);

        return $ts ? date('Y-m-d H:i', $ts) : $value;
    }

    private static function formatDate(string $value): string
    {
        if ($value === '') {
            return '-';
        }
        $ts = strtotime($value);

        return $ts ? date('Y-m-d H:i', $ts) : $value;
    }
}
