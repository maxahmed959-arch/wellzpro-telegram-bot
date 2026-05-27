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
    'bank_name' => getenv('TELEGRAM_BANK_NAME') ?: 'urpay',
    'bank_holder' => getenv('TELEGRAM_BANK_HOLDER') ?: '',

    'plans' => [
        'month' => ['label' => 'شهر (30 يوم)', 'price' => 25],
        'two_months' => ['label' => 'شهرين (60 يوم)', 'price' => 50],
        'quarter' => ['label' => '3 أشهر (90 يوم)', 'price' => 75],
    ],

    /** أزرار معلومات — أكواد المناطق في تطبيق WellzPro */
    'area_codes' => [
        'pharmacy' => [
            'button' => '💊 أكواد الصيدلية',
            'code' => 'YAN-SIH-KHU',
            'area_id' => 543,
            'label' => 'صيدلية ينبع',
            'api_shift_code' => 'YAN-SIH-KHU001',
        ],
        'grocery' => [
            'button' => '🛒 أكواد البقالة',
            'code' => 'YAN-KHU',
            'area_id' => 394,
            'label' => 'بقالة الصاعدة (ينبع)',
        ],
        'riyadh_qur' => [
            'button' => '🏙️ قرطبة',
            'code' => 'RUH-QUR',
            'area_id' => 432,
            'label' => 'قرطبة (RUH-QUR)',
            'api_shift_code' => 'RUH-QUR001',
        ],
    ],

    'how_to_run_button' => '📖 طريقة التشغيل',

    /** زر إرسال APK — ضع رابط مباشر (GitHub Releases أو ملف على السحابة) */
    'app_download_button' => getenv('APP_DOWNLOAD_BUTTON') ?: '📲 تحميل التطبيق',
    'app_download_url' => getenv('APP_DOWNLOAD_URL') ?: '',
];
