<?php

declare(strict_types=1);

namespace Wellz;

/**
 * سجل تدقيق — كل عملية توليد/حذف/تعطيل مع المستخدم والوقت.
 */
final class AuditLogger
{
    public function __construct(private string $dataDir) {}

    /** @param array<string, mixed> $meta */
    public function log(string $action, int $userId, ?string $username, array $meta = []): void
    {
        $dir = $this->dataDir.'/audit';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $entry = [
            'ts' => date('c'),
            'action' => $action,
            'user_id' => $userId,
            'username' => $username,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'meta' => $meta,
        ];
        $file = $dir.'/'.date('Y-m-d').'.jsonl';
        file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 20): array
    {
        $file = $this->dataDir.'/audit/'.date('Y-m-d').'.jsonl';
        if (! is_file($file)) {
            return [];
        }
        $lines = array_filter(array_map('trim', file($file) ?: []));
        $rows = [];
        foreach (array_slice(array_reverse($lines), 0, $limit) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
