<?php

/**
 * يوقف تعارض Render/ويب هوك — يبقي التشغيل المحلي فقط.
 * تشغيل: php fix-webhook.php
 */

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

$token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
if ($token === '') {
    echo "TELEGRAM_BOT_TOKEN فارغ في .env\n";
    exit(1);
}

function tg(string $token, string $method, array $params = []): array
{
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $json = json_decode((string) $body, true);

    return is_array($json) ? $json : [];
}

echo "=== معلومات الويب هوك (مصدر النسخة القديمة غالباً) ===\n";
$info = tg($token, 'getWebhookInfo');
$wh = $info['result'] ?? [];
$url = (string) ($wh['url'] ?? '');
echo 'Webhook URL: '.($url !== '' ? $url : '(فارغ — جيد للتشغيل المحلي)')."\n";
echo 'Pending updates: '.($wh['pending_update_count'] ?? 0)."\n\n";

if ($url !== '') {
    echo "!!! يوجد ويب هوك نشط — غالباً Render يرسل الردود القديمة !!!\n";
    echo "احذف الخدمة wellzpro-telegram-bot من Render أو عطّلها.\n\n";
}

echo "=== حذف الويب هوك ===\n";
$del = tg($token, 'deleteWebhook', ['drop_pending_updates' => 'false']);
echo ($del['ok'] ?? false) ? "تم deleteWebhook\n" : ('فشل: '.json_encode($del)."\n");

echo "\n=== البوت ===\n";
$me = tg($token, 'getMe');
echo 'Username: @'.($me['result']['username'] ?? '?')."\n";

echo "\nالخطوة التالية: شغّل stop-and-start.bat ثم /start في تيليجرام\n";
echo "يجب أن ترى الأسعار: 25 / 50 / 75 / 199 بدون @Uniquecord\n";
