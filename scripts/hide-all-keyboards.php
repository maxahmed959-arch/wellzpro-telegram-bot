<?php

/**
 * يرسل remove_keyboard لكل chat معروف محلياً (sessions / orders / admins).
 * الاستخدام: php scripts/hide-all-keyboards.php
 */

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

$config = require dirname(__DIR__).'/config.php';
$token = (string) ($config['bot_token'] ?? '');
if ($token === '') {
    fwrite(STDERR, "TELEGRAM_BOT_TOKEN فارغ\n");
    exit(1);
}

$dataDir = dirname(__DIR__).'/data';
$chatIds = [];

foreach (glob($dataDir.'/sessions/*.json') ?: [] as $file) {
    $base = basename($file, '.json');
    if (ctype_digit($base)) {
        $chatIds[(int) $base] = true;
    }
}

foreach (glob($dataDir.'/orders/*.json') ?: [] as $file) {
    $raw = json_decode((string) file_get_contents($file), true);
    if (is_array($raw) && isset($raw['chat_id'])) {
        $chatIds[(int) $raw['chat_id']] = true;
    }
}

$adminsFile = $dataDir.'/admins/admins.json';
if (is_file($adminsFile)) {
    $admins = json_decode((string) file_get_contents($adminsFile), true);
    if (is_array($admins)) {
        foreach ($admins as $id) {
            if (is_numeric($id)) {
                $chatIds[(int) $id] = true;
            }
        }
    }
}

foreach ($config['admin_ids'] ?? [] as $id) {
    if (is_numeric($id)) {
        $chatIds[(int) $id] = true;
    }
}

$ids = array_keys($chatIds);
sort($ids);

if ($ids === []) {
    echo "لا توجد محادثات معروفة في data/\n";
    exit(0);
}

$text = "⏸ البوت متوقف مؤقتاً.";
$markup = json_encode(['remove_keyboard' => true, 'selective' => false], JSON_UNESCAPED_UNICODE);
$ok = 0;
$fail = 0;

foreach ($ids as $chatId) {
    $qs = http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
        'reply_markup' => $markup,
    ]);
    $url = 'https://api.telegram.org/bot'.$token.'/sendMessage?'.$qs;
    $raw = @file_get_contents($url);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($json) && ($json['ok'] ?? false)) {
        $ok++;
        echo "OK chat={$chatId}\n";
    } else {
        $fail++;
        $desc = is_array($json) ? (string) ($json['description'] ?? 'error') : 'no response';
        echo "FAIL chat={$chatId} ({$desc})\n";
    }
    usleep(35000);
}

echo "done ok={$ok} fail={$fail} total=".count($ids)."\n";
echo "bot_enabled=".var_export($config['bot_enabled'] ?? null, true)."\n";
echo "مهم: إن كان Render بدون TELEGRAM_BOT_ENABLED=false ستعود الأزرار عند أي رد من البوت الحي.\n";
