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
        // AyosPush accepts 5 requests/s (and 1000/h) per account.
        'requests_per_second' => (int) env('AYOSPUSH_REQUESTS_PER_SECOND', 4),
    ],

    /*
    | Telegram Bot API. Each application saves the token of its own bot (from
    | @BotFather); only the endpoint is shared (a local Bot API server works).
    */
    'telegram' => [
        'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
        'timeout' => (int) env('TELEGRAM_TIMEOUT', 15),
        // Sending a template's file the first time uploads it: kept under the
        // 60 s the Horizon workers give a job.
        'upload_timeout' => (int) env('TELEGRAM_UPLOAD_TIMEOUT', 45),
        // Telegram allows about 30 messages per second per bot.
        'messages_per_second' => (int) env('TELEGRAM_MESSAGES_PER_SECOND', 25),
        // Where template files are kept (config/filesystems.php). "local" is
        // storage/app/private: a persistent volume in Docker.
        'media_disk' => env('TELEGRAM_MEDIA_DISK', 'local'),
    ],

];
