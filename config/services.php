<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'sms_gateway' => [
        'base_url' => env('SMS_GATEWAY_BASE_URL', 'https://api.sms-gate.app/3rdparty/v1'),
        'username' => env('SMS_GATEWAY_USERNAME'),
        'password' => env('SMS_GATEWAY_PASSWORD'),
        'sim_number' => env('SMS_GATEWAY_SIM_NUMBER'),
    ],

    'supabase_storage' => [
        // Project URL, e.g. https://xxxxxxxx.supabase.co — from Supabase's
        // dashboard under Project Settings > API. Not the same as the
        // Postgres pooler host used for DB_HOST.
        'url' => env('SUPABASE_URL'),
        // The *service_role* key (not the anon/public key) — it bypasses
        // Row Level Security so the server can upload without a user session.
        'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
        'bucket' => env('SUPABASE_STORAGE_BUCKET', 'payment-proofs'),
    ],

];
