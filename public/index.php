<?php

declare(strict_types=1);

require __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../bot.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    $ver = is_file(__DIR__.'/../VERSION.txt')
        ? trim((string) file_get_contents(__DIR__.'/../VERSION.txt'))
        : 'unknown';
    echo "WellzPro Telegram Bot — Samurai MiniBot — cloud OK — {$ver}\n";
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    exit;
}

$secret = getenv('TELEGRAM_WEBHOOK_SECRET') ?: '';
if ($secret !== '') {
    $header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (! hash_equals($secret, (string) $header)) {
        http_response_code(403);
        exit;
    }
}

$raw = file_get_contents('php://input');
$update = json_decode($raw ?: '', true);
if (! is_array($update)) {
    http_response_code(400);
    exit;
}

static $bot;
if ($bot === null) {
    $bot = new WellzTelegramBot($config);
    $bot->setupOnce();
}

$bot->processUpdate($update);
http_response_code(200);
echo 'ok';
