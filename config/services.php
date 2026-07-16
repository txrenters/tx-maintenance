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
        // Sender numbers we own. `from` is the general line; `maintenance_from`
        // is the dedicated maintenance line.
        'from' => env('TWILIO_PHONE_NUMBER'),
        'maintenance_from' => env('MAINTENANC_TWILIO_PHONE_NUMBER'),
        // SMS alert to the WOC when a work order is classified as an
        // emergency. Off by default so local/testing environments never
        // text real people; set EMERGENCY_SMS_ENABLED=true in production.
        'emergency_sms' => env('EMERGENCY_SMS_ENABLED', false),
        // The five SMS features below are ON by default so a production deploy
        // works immediately. Local .env and phpunit.xml explicitly set every
        // flag to false, so development and tests never text real people.
        // Fresh-start backfill migrations guarantee that enabling them never
        // blasts the pre-existing backlog — only events after the deploy.
        //
        // Daily follow-up text to a vendor who still has no service schedule 3
        // business days after being assigned.
        'schedule_followup_sms' => env('SCHEDULE_FOLLOWUP_SMS_ENABLED', true),
        // Text the primary property owner via the owner<->WOC conversation when
        // a vendor is assigned.
        'owner_assignment_sms' => env('OWNER_ASSIGNMENT_SMS_ENABLED', true),
        // Text the property owner via the owner<->WOC conversation when a vendor
        // sets the service appointment (details + ask if they want to join a
        // call with the technician or approve the work order).
        'owner_schedule_sms' => env('OWNER_SCHEDULE_SMS_ENABLED', true),
        // Text the primary property owner via the owner<->WOC conversation when
        // a new service request comes in (confirmation + description).
        'owner_service_request_sms' => env('OWNER_SERVICE_REQUEST_SMS_ENABLED', true),
        // Text the tenant a no-login portal link (photo upload) when their work
        // order is marked as a tenant easy fix, plus reminders until done.
        'tenant_portal_sms' => env('TENANT_PORTAL_SMS_ENABLED', true),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5.1-mini'),
    ],

];
