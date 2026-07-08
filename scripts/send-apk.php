<?php

declare(strict_types=1);

/**
 * عامل إرسال APK في الخلفية — يُستدعى من dispatchApkSend عبر:
 *   php scripts/send-apk.php <chatId>
 *
 * يعالج طابور الإرسال بالتسلسل بحيث يعود الويب هوك فوراً بلا انتظار التنزيل.
 */
putenv('WELLZ_BOT_NORUN=1');
$_ENV['WELLZ_BOT_NORUN'] = '1';

$chatId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($chatId <= 0) {
    fwrite(STDERR, "usage: php send-apk.php <chatId>\n");
    exit(1);
}

require __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../bot.php';

$bot = new WellzTelegramBot($config);
$bot->processApkQueue($chatId);
