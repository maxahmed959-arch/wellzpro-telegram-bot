<?php

declare(strict_types=1);

require __DIR__.'/../bootstrap.php';

$token = (string) ($config['bot_token'] ?? '');
if ($token === '') {
    fwrite(STDERR, "TELEGRAM_BOT_TOKEN missing\n");
    exit(1);
}

$base = rtrim(
    getenv('BOT_PUBLIC_URL')
    ?: getenv('RENDER_EXTERNAL_URL')
    ?: '',
    '/'
);
if ($base === '') {
    fwrite(STDERR, "Set BOT_PUBLIC_URL or RENDER_EXTERNAL_URL (e.g. https://wellzpro-bot.onrender.com)\n");
    exit(1);
}

$webhookUrl = $base.'/';
$secret = getenv('TELEGRAM_WEBHOOK_SECRET') ?: '';

$params = ['url' => $webhookUrl, 'drop_pending_updates' => 'true'];
if ($secret !== '') {
    $params['secret_token'] = $secret;
}

$ch = curl_init("https://api.telegram.org/bot{$token}/setWebhook");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $params,
    CURLOPT_TIMEOUT => 30,
]);
$body = curl_exec($ch);
curl_close($ch);
$json = json_decode((string) $body, true);

if (! ($json['ok'] ?? false)) {
    fwrite(STDERR, 'setWebhook failed: '.$body."\n");
    exit(1);
}

echo "Webhook set: {$webhookUrl}\n";
