<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | WhatsApp goes through the AyosPush public API v1. Each application stores
    | its own AyosPush API key and secret (see whatsapp_settings); only the
    | endpoint is shared.
    */
    'ayospush' => [
        'base_url' => env('AYOSPUSH_API_URL', 'https://ayospush.com/api/v1'),
        'timeout' => (int) env('AYOSPUSH_TIMEOUT', 20),
        // Creating a template calls Meta synchronously on AyosPush's side (and
        // waits 5 s more for AUTHENTICATION templates).
        'submit_timeout' => (int) env('AYOSPUSH_SUBMIT_TIMEOUT', 60),
    ],

];
