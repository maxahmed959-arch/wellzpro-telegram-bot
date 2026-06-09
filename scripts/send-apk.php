<?php

declare(strict_types=1);

/**
 * معالجة طابور إرسال APK بالتسلسل — لا يعلق الويب هوك.
 * الاستخدام: php scripts/send-apk.php CHAT_ID
 */

require __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../bot.php';

$chatId = (int) ($argv[1] ?? 0);

if ($chatId <= 0) {
    fwrite(STDERR, "usage: php send-apk.php CHAT_ID\n");
    exit(1);
}

$bot = new WellzTelegramBot($config);
$bot->processApkQueue($chatId);
