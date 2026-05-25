<?php

return [
    'bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: '',
    'admin_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', getenv('TELEGRAM_ADMIN_IDS') ?: '')
    ))),
    /** لتسجيل نفسك كأدمن: أرسل للبوت /admin الرمز */
    'admin_pin' => getenv('TELEGRAM_ADMIN_PIN') ?: 'WellzPro2026',
    'bank_account' => getenv('TELEGRAM_BANK_ACCOUNT') ?: '0545155289',
    'bank_holder' => getenv('TELEGRAM_BANK_HOLDER') ?: '',

    'plans' => [
        'month' => ['label' => 'شهر (30 يوم)', 'price' => 30],
        'two_months' => ['label' => 'شهرين (60 يوم)', 'price' => 60],
        'quarter' => ['label' => '3 أشهر (90 يوم)', 'price' => 90],
        'lifetime' => ['label' => 'مدى الحياة', 'price' => 299],
    ],
];
