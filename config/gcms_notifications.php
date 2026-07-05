<?php

return [
    'push' => [
        'enabled' => env('PUSH_NOTIFICATIONS_ENABLED', false),
        'fcm' => [
            'project_id' => env('FCM_PROJECT_ID'),
            'credentials_path' => env('FCM_CREDENTIALS_PATH'),
            'server_key' => env('FCM_SERVER_KEY'),
        ],
    ],

    'sms' => [
        'enabled' => env('SMS_NOTIFICATIONS_ENABLED', false),
        'provider' => env('SMS_PROVIDER', 'log'),
        'from' => env('SMS_FROM', 'GCMS'),
        'twilio' => [
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],
    ],
];
