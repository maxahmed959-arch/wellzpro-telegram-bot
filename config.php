<?php

return [
    'bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: '',
    'admin_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', getenv('TELEGRAM_ADMIN_IDS') ?: '')
    ))),
    /** لتسجيل نفسك كأدمن: أرسل للبوت /admin الرمز */
    'admin_pin' => getenv('TELEGRAM_ADMIN_PIN') ?: 'WellzPro2026',
    'bank_account' => getenv('TELEGRAM_BANK_ACCOUNT') ?: '0547196179',
    'bank_name' => getenv('TELEGRAM_BANK_NAME') ?: 'urpay',
    'bank_holder' => getenv('TELEGRAM_BANK_HOLDER') ?: '',

    'plans' => [
        'month' => ['label' => 'شهر (30 يوم)', 'price' => 25],
        'two_months' => ['label' => 'شهرين (60 يوم)', 'price' => 50],
        'quarter' => ['label' => '3 أشهر (90 يوم)', 'price' => 75],
    ],

    /** أكواد المناطق — زر واحد يعرض القائمة كاملة */
    'area_codes_button' => '📍 أكواد المناطق',

    'area_codes' => [
        'pharmacy' => [
            'code' => 'YAN-SIH-KHU',
            'area_id' => 543,
            'label' => 'صيدلية ينبع',
            'api_shift_code' => 'YAN-SIH-KHU001',
        ],
        'grocery' => [
            'code' => 'YAN-KHU',
            'area_id' => 394,
            'label' => 'بقالة الصاعدة (ينبع)',
            'api_shift_code' => 'YAN-KHU001',
        ],
        'riyadh_qur' => [
            'code' => 'RUH-QUR',
            'area_id' => 432,
            'label' => 'قرطبة (الرياض)',
            'api_shift_code' => 'RUH-QUR001',
        ],
        'riyadh_mlq' => [
            'code' => 'RUH-MLQ',
            'area_id' => 430,
            'label' => 'الملقا (الرياض)',
            'api_shift_code' => 'RUH-MLQ001',
        ],
        'riyadh_blv' => [
            'code' => 'RUH-BLV',
            'area_id' => 422,
            'label' => 'بوليفارد (الرياض)',
            'api_shift_code' => 'RUH-BLV001',
        ],
        'riyadh_flh' => [
            'code' => 'RUH-FLH',
            'area_id' => 440,
            'label' => 'الفلاح (الرياض)',
            'api_shift_code' => 'RUH-FLH001',
        ],
    ],

    'how_to_run_button' => '📖 طريقة التشغيل',

    /** معطّل — دليل «طريقة التشغيل» نص فقط */
    'how_to_run_video_file_id' => '',
    'how_to_run_video_url' => '',

    /** زر إرسال APK — رابط GitHub Releases (يُستخرج منه مجلد الإصدار لباقي النسخ) */
    'app_download_button' => getenv('APP_DOWNLOAD_BUTTON') ?: '📲 تحميل التطبيق',
    'app_download_url' => getenv('APP_DOWNLOAD_URL') ?: 'https://github.com/maxahmed959-arch/wellzpro-telegram-bot/releases/download/v1.0.0/samu.8.apk',
    'app_download_filename' => getenv('APP_DOWNLOAD_FILENAME') ?: 'samu.8.apk',

    /** نسخ APK — تظهر كأزرار عند الضغط على «تحميل التطبيق» */
    'app_apk_variants' => [
        [
            'key' => 'full',
            'label' => '📱 النسخة الكاملة',
            'filename' => getenv('APP_DOWNLOAD_FILENAME') ?: 'samu.8.apk',
            'hint' => 'موصى بها — تعمل على معظم الأجهزة',
        ],
        [
            'key' => 'v7a',
            'label' => '📱 v7a (32-bit)',
            'filename' => 'v7a.apk',
            'hint' => 'للأجهزة القديمة (معالج 32-bit)',
        ],
        [
            'key' => 'v8a',
            'label' => '📱 v8a (64-bit)',
            'filename' => 'v8a.apk',
            'hint' => 'للأجهزة الحديثة (معالج 64-bit)',
        ],
    ],
];
