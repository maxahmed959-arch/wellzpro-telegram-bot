<?php

declare(strict_types=1);

namespace Wellz;

/**
 * تحديد معدل الطلبات لكل مستخدم (منع الإساءة).
 */
final class RateLimiter
{
    public function __construct(
        private string $dataDir,
        private int $maxPerMinute = 5,
    ) {}

    public function allow(int $userId, string $bucket = 'default'): bool
    {
        $dir = $this->dataDir.'/rate_limit';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir.'/'.$userId.'_'.$bucket.'.json';
        $now = time();
        $window = 60;
        $hits = [];
        if (is_file($file)) {
            $hits = json_decode((string) file_get_contents($file), true);
            if (! is_array($hits)) {
                $hits = [];
            }
            $hits = array_values(array_filter($hits, fn ($t) => is_int($t) && $t > $now - $window));
        }
        if (count($hits) >= $this->maxPerMinute) {
            return false;
        }
        $hits[] = $now;
        file_put_contents($file, json_encode($hits));

        return true;
    }
}
