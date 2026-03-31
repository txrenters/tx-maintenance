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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'propertyware' => [
        'url' => env('PROPERTYWARE_URL'),
        'username' => env('PROPERTYWARE_USERNAME'),
        'password' => env('PROPERTYWARE_PASSWORD'),
        'client_id' => env('PROPERTYWARE_CLIENT_ID'),
        'client_secret_key' => env('PROPERTYWARE_CLIENT_SECRET_KEY'),
        'system_id' => env('PROPERTYWARE_SYSTEM_ID'),
    ],

    'asana' => [
        'token' => env('ASANA_ACCESS_TOKEN'),
        'workspace_id' => env('ASANA_WORKSPACE_ID'),
        'user_gid' => env('ASANA_USER_GID'), // Ensure this is in your .env
        'base_url' => 'https://app.asana.com/api/1.0/',
        'projects' => explode(',', env('ASANA_PROJECT_IDS')),
        'webhook_url' => env('ASANA_WEBHOOK_URL'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'status_callback_url' => env('TWILIO_STATUS_CALLBACK_URL'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5.1-mini'),
    ],

];
