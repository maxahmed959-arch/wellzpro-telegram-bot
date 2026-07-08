<?php

declare(strict_types=1);

namespace Wellz;

/**
 * إحصائيات التراخيص — لوحة تحكم الأدمن.
 */
final class StatsService
{
    public function __construct(
        private FirebaseClient $firebase,
        private array $config,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $rows = $this->firebase->listAllLicenses();
        $stats = [
            'total' => count($rows),
            'pending' => 0,
            'active' => 0,
            'disabled' => 0,
            'expired' => 0,
            'by_plan' => [],
        ];
        foreach ($rows as $row) {
            match ($row['computed_status'] ?? '') {
                'pending' => $stats['pending']++,
                'active' => $stats['active']++,
                'disabled' => $stats['disabled']++,
                'expired' => $stats['expired']++,
                default => null,
            };
            $plan = (string) ($row['plan'] ?? 'unknown');
            $stats['by_plan'][$plan] = ($stats['by_plan'][$plan] ?? 0) + 1;
        }

        return ['stats' => $stats, 'licenses' => $rows];
    }

    public function formatDashboardText(array $snapshot): string
    {
        $s = $snapshot['stats'];
        $lines = [
            '📊 <b>لوحة إحصائيات WellzPro</b>',
            '',
            '📦 إجمالي الأكواد: <b>'.($s['total'] ?? 0).'</b>',
            '🟢 نشطة (مُفعّلة): <b>'.($s['active'] ?? 0).'</b>',
            '🟡 بانتظار التفعيل: <b>'.($s['pending'] ?? 0).'</b>',
            '🔴 منتهية: <b>'.($s['expired'] ?? 0).'</b>',
            '⛔ معطّلة: <b>'.($s['disabled'] ?? 0).'</b>',
            '',
            '📈 <b>توزيع المدد:</b>',
        ];
        $plans = $this->config['license_plans'] ?? [];
        foreach ($s['by_plan'] ?? [] as $plan => $count) {
            $label = (string) (($plans[$plan]['label'] ?? $plan));
            $bar = $this->textBar((int) $count, max(1, (int) ($s['total'] ?? 1)));
            $lines[] = $label.' — '.$count.' '.$bar;
        }
        $lines[] = '';
        $lines[] = '🕐 <b>آخر 10 أكواد:</b>';
        foreach (array_slice($snapshot['licenses'], 0, 10) as $row) {
            $lines[] = '• <code>'.($row['key'] ?? '').'</code> — '
                .($row['computed_status'] ?? '').' — '
                .($row['plan'] ?? '').' — '
                .($row['created_at'] ?? '-');
        }

        return implode("\n", $lines);
    }

    public function buildCsv(array $snapshot): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        fputcsv($out, ['code', 'plan', 'status', 'expires_at', 'device_id', 'created_at', 'created_by']);
        foreach ($snapshot['licenses'] as $row) {
            fputcsv($out, [
                $row['key'] ?? '',
                $row['plan'] ?? '',
                $row['computed_status'] ?? '',
                $row['expires_at'] ?? '',
                $row['deviceId'] ?? '',
                $row['created_at_raw'] ?? '',
                $row['created_by'] ?? '',
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv !== false ? $csv : '';
    }

    private function textBar(int $value, int $max): string
    {
        $len = (int) round(($value / $max) * 10);
        $len = max(0, min(10, $len));

        return str_repeat('█', $len).str_repeat('░', 10 - $len);
    }
}
