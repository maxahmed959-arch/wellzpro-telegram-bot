<?php

declare(strict_types=1);

/**
 * إرسال APK في الخلفية — لا يعلق الويب هوك.
 * الاستخدام: php scripts/send-apk.php CHAT_ID VARIANT_KEY
 */

require __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../bot.php';

$chatId = (int) ($argv[1] ?? 0);
$variantKey = trim((string) ($argv[2] ?? ''));

if ($chatId <= 0 || $variantKey === '') {
    fwrite(STDERR, "usage: php send-apk.php CHAT_ID VARIANT_KEY\n");
    exit(1);
}

$bot = new WellzTelegramBot($config);
$bot->deliverApkVariant($chatId, $variantKey);
