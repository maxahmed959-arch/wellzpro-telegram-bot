<?php

declare(strict_types=1);

namespace Wellz;

/**
 * مهام مجدولة — تذكير انتهاء الصلاحية وتحديث الحالات.
 */
final class CronService
{
    public function __construct(
        private FirebaseClient $firebase,
        private string $dataDir,
        private int $reminderDays = 3,
    ) {}

    /**
     * @param callable(int, string): void $notify  chatId, message
     * @return array{reminders: int, expired_notices: int, errors: int}
     */
    public function runDaily(callable $notify): array
    {
        $result = ['reminders' => 0, 'expired_notices' => 0, 'errors' => 0];
        if (! $this->firebase->isConfigured()) {
            return $result;
        }
        $licenses = $this->firebase->listAllLicenses();
        $licenseByCode = [];
        foreach ($licenses as $row) {
            $licenseByCode[$row['key'] ?? ''] = $row;
        }
        foreach (glob($this->dataDir.'/orders/*.json') ?: [] as $file) {
            $order = json_decode((string) file_get_contents($file), true);
            if (! is_array($order) || ($order['status'] ?? '') !== 'delivered') {
                continue;
            }
            $chatId = (int) ($order['chat_id'] ?? 0);
            $code = strtoupper(trim((string) ($order['license_key'] ?? '')));
            if ($chatId === 0 || $code === '') {
                continue;
            }
            $lic = $licenseByCode[$code] ?? null;
            if ($lic === null) {
                continue;
            }
            $expiry = $this->parseExpiry((string) ($lic['expires_at'] ?? ''));
            if ($expiry === null) {
                continue;
            }
            $daysLeft = (int) floor(($expiry - time()) / 86400);
            if ($daysLeft === $this->reminderDays && empty($order['reminder_sent'])) {
                try {
                    $notify($chatId, "⏰ <b>تذكير WellzPro</b>\n\nاشتراكك <code>{$code}</code> ينتهي خلال <b>{$this->reminderDays} أيام</b>.\nجدّد عبر /start");
                    $order['reminder_sent'] = date('c');
                    file_put_contents($file, json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    $result['reminders']++;
                } catch (\Throwable) {
                    $result['errors']++;
                }
            }
            if ($daysLeft < 0 && empty($order['expired_notice_sent'])) {
                try {
                    $notify($chatId, "🔴 <b>انتهى اشتراك WellzPro</b>\n\nالرمز <code>{$code}</code> لم يعد فعّالاً.\nجدّد الآن: /start");
                    $order['expired_notice_sent'] = date('c');
                    file_put_contents($file, json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    $result['expired_notices']++;
                } catch (\Throwable) {
                    $result['errors']++;
                }
            }
        }

        return $result;
    }

    private function parseExpiry(string $raw): ?int
    {
        if ($raw === '' || strtolower($raw) === 'lifetime') {
            return null;
        }
        $ts = strtotime($raw);

        return $ts !== false ? $ts : null;
    }
}
