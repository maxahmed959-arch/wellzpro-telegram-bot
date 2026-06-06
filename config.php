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

    /**
     * فيديو توضيحي لزر «طريقة التشغيل» (اختياري — واحد يكفي):
     * - HOW_TO_RUN_VIDEO_FILE_ID من تيليجرام (الأفضل على Render)
     * - HOW_TO_RUN_VIDEO_URL رابط mp4 مباشر
     * - أو ضع الملف: telegram-bot/assets/how-to-run.mp4
     */
    'how_to_run_video_file_id' => getenv('HOW_TO_RUN_VIDEO_FILE_ID') ?: '',
    'how_to_run_video_url' => getenv('HOW_TO_RUN_VIDEO_URL') ?: '',

    /** زر إرسال APK — رابط GitHub Releases (samu.apk) */
    'app_download_button' => getenv('APP_DOWNLOAD_BUTTON') ?: '📲 تحميل التطبيق',
    'app_download_url' => getenv('APP_DOWNLOAD_URL') ?: '',
    'app_download_filename' => getenv('APP_DOWNLOAD_FILENAME') ?: 'samu.apk',
];
