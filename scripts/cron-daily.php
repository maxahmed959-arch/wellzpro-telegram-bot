<?php

declare(strict_types=1);

/**
 * مهام مجدولة يومية — تذكير انتهاء الاشتراك.
 * Render Cron Job: php scripts/cron-daily.php
 * أو محلياً: php scripts/cron-daily.php
 */
putenv('WELLZ_BOT_NORUN=1');

require __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../bot.php';

use Wellz\CronService;
use Wellz\FirebaseClient;

$firebase = new FirebaseClient($config, __DIR__.'/../data');
$cron = new CronService(
    $firebase,
    __DIR__.'/../data',
    (int) ($config['expiry_reminder_days'] ?? 3),
);

$bot = new WellzTelegramBot($config);
$ref = new ReflectionClass($bot);
$send = $ref->getMethod('send');
$send->setAccessible(true);

$result = $cron->runDaily(function (int $chatId, string $text) use ($bot, $send): void {
    $send->invoke($bot, $chatId, $text, null, true);
});

echo date('c').' cron-daily: '.json_encode($result, JSON_UNESCAPED_UNICODE)."\n";
