<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telegram Bot Token
    |--------------------------------------------------------------------------
    */
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Webhook URL
    |--------------------------------------------------------------------------
    */
    'webhook_url' => env('TELEGRAM_WEBHOOK_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Bot Username
    |--------------------------------------------------------------------------
    */
    'bot_username' => env('TELEGRAM_BOT_USERNAME', ''),

    /*
    |--------------------------------------------------------------------------
    | Admin Chat IDs (bildirishnomalar uchun)
    |--------------------------------------------------------------------------
    */
    'admin_ids' => array_filter(explode(',', env('TELEGRAM_ADMIN_IDS', ''))),

    /*
    |--------------------------------------------------------------------------
    | Currency API Key (valyuta kurslari uchun)
    |--------------------------------------------------------------------------
    */
    'currency_api_key' => env('CURRENCY_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key (AI Yordamchi uchun)
    |--------------------------------------------------------------------------
    */
    'openai_api_key' => env('OPENAI_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Standart til
    |--------------------------------------------------------------------------
    */
    'default_language' => env('TELEGRAM_DEFAULT_LANG', 'uz'),

    /*
    |--------------------------------------------------------------------------
    | Qo'llab-quvvatlanadigan tillar
    |--------------------------------------------------------------------------
    */
    'languages' => ['uz', 'ru', 'en'],

    /*
    |--------------------------------------------------------------------------
    | Vazifa kategoriyalari - O'ZBEK TILIDA
    |--------------------------------------------------------------------------
    */
    'task_categories' => [
        'work' => '💼 Ish',
        'home' => '🏠 Uy',
        'personal' => '👤 Shaxsiy',
        'finance' => '💰 Moliya',
        'health' => '🏥 Salomatlik',
        'education' => '📚 Ta\'lim',
        'shopping' => '🛒 Xarid',
        'other' => '📋 Boshqa',
    ],

    /*
    |--------------------------------------------------------------------------
    | Xarajat kategoriyalari - O'ZBEK TILIDA
    |--------------------------------------------------------------------------
    */
    'expense_categories' => [
        'food' => '🍔 Oziq-ovqat',
        'transport' => '🚗 Transport',
        'work' => '💼 Ish',
        'repair' => '🔧 Ta\'mirlash',
        'entertainment' => '🎬 Ko\'ngil ochar',
        'equipment' => '🖥️ Jihozlar',
        'health' => '🏥 Salomatlik',
        'education' => '📚 Ta\'lim',
        'utilities' => '💡 Kommunal',
        'clothing' => '👕 Kiyim',
        'other' => '📋 Boshqa',
    ],

    /*
    |--------------------------------------------------------------------------
    | Daromad kategoriyalari - O'ZBEK TILIDA
    |--------------------------------------------------------------------------
    */
    'income_categories' => [
        'salary' => '💵 Maosh',
        'freelance' => '💻 Frilanc',
        'investment' => '📈 Investitsiya',
        'gift' => '🎁 Sovg\'a',
        'refund' => '↩️ Qaytarilgan',
        'bonus' => '🎯 Bonus',
        'business' => '🏢 Biznes',
        'other' => '📋 Boshqa',
    ],

    /*
    |--------------------------------------------------------------------------
    | Muhimlik darajalari - O'ZBEK TILIDA
    |--------------------------------------------------------------------------
    */
    'priorities' => [
        'high' => '🔴 Yuqori',
        'medium' => '🟡 O\'rta',
        'low' => '🟢 Past',
    ],

    /*
    |--------------------------------------------------------------------------
    | O'yin elementlari sozlamalari
    |--------------------------------------------------------------------------
    */
    'gamification' => [
        'points_per_task' => 10,
        'points_high_priority' => 20,
        'points_on_time' => 5,
        'points_per_rating_star' => 2,
        'badges' => [
            'beginner' => ['name' => '🌟 Boshlang\'ich', 'points' => 0],
            'active' => ['name' => '⭐ Faol', 'points' => 100],
            'productive' => ['name' => '🏅 Samarali', 'points' => 500],
            'master' => ['name' => '🏆 Usta', 'points' => 1000],
            'legend' => ['name' => '👑 Afsona', 'points' => 5000],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bildirishnoma vaqtlari
    |--------------------------------------------------------------------------
    */
    'notification_times' => [
        'morning' => '08:00',
        'afternoon' => '13:00',
        'evening' => '19:00',
    ],
];
