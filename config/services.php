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
        // HOA violations: text the tenant their portal link + daily reminders
        // until the 5-business-day deadline, and email the corrected
        // confirmation to tenant + owner.
        'hoa_violation_sms' => env('HOA_VIOLATION_SMS_ENABLED', true),
        // Daily follow-up text to the tenant, starting the day after a vendor is
        // assigned, asking whether the vendor has reached out to them. Stops
        // once a service schedule exists (or the cap is hit).
        'tenant_vendor_followup_sms' => env('TENANT_VENDOR_FOLLOWUP_SMS_ENABLED', true),
        // Text the tenant via the tenant<->WOC conversation when a third-party
        // vendor is assigned, telling them the vendor will contact them to
        // schedule. THMP (in-house) jobs are skipped — THMP messages manually.
        'tenant_assignment_sms' => env('TENANT_ASSIGNMENT_SMS_ENABLED', true),
    ],

    'hoa' => [
        // Business days the tenant has, counted from the notice date, to
        // complete the violation items before staff are flagged to send a vendor.
        'deadline_business_days' => (int) env('HOA_DEADLINE_BUSINESS_DAYS', 5),
        // Try to create the work order in PropertyWare first; when disabled (or
        // when the create call fails) the work order is created locally only.
        'pw_create_enabled' => env('HOA_PW_CREATE_ENABLED', true),
        // PropertyWare's type and category are curated picklists that reject
        // unknown values, so HOA work orders are filed under an existing valid
        // pair. HOA identity is tracked by the HOA upload token, not these
        // strings (the PW import overwrites them on the next sync).
        'pw_category' => env('HOA_PW_CATEGORY', 'General Maintenance'),
        'pw_type' => env('HOA_PW_TYPE', 'General'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5.1-mini'),
    ],

    'work_order' => [
        // Automatically assign the prior vendor when a new work order is a
        // confident repeat (same issue type + same building -> same vendor).
        // ON by default so a production deploy starts reusing repeat vendors
        // immediately. Local .env and phpunit.xml pin this false so development
        // and tests never email a real vendor or push an assignment to
        // PropertyWare.
        'auto_assign_vendor' => env('VENDOR_AUTO_ASSIGN_ENABLED', true),
    ],

    'microsoft' => [
        'tenant_id' => env('MICROSOFT_TENANT_ID'),
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'mailbox' => env('MICROSOFT_MAILBOX', 'workorders@texasrenters.com'),
        'job_reminder_mailbox' => env('MICROSOFT_JOB_REMINDER_MAILBOX', 'service@txhomemp.com'),
        'turnover_mailbox' => env('MICROSOFT_TURNOVER_MAILBOX', 'thmp@texasrenters.com'),
    ],

    'operation_accounting' => [
        'email' => env('OPERATION_ACCOUNTING_EMAIL', 'oa@texasrenters.com'),
        // These notifications are one-way. Replies are routed here so they
        // never land in the monitored/synced workorders@ inbox.
        'no_reply_email' => env('OA_NO_REPLY_EMAIL', 'no-reply@texasrenters.com'),
        // Email Operation Accounting whenever an invoice is uploaded on a
        // Turnover work order. Internal-only mail that fires per new upload,
        // so it is safe to have on by default.
        'turnover_invoice_notifications' => env('OA_TURNOVER_INVOICE_NOTIFICATIONS', true),
    ],

    'jobber' => [
        'graphql_url' => env('JOBBER_GRAPHQL_URL', 'https://api.getjobber.com/api/graphql'),
        'api_version' => env('JOBBER_API_VERSION'),
        // When THMP is assigned to a work order, create the matching job in
        // Jobber and store its link on the work order. ON by default so a
        // production deploy creates jobs immediately (the n8n "Create Job"
        // workflow that used to do this is disabled — this app owns it now).
        // Local .env and phpunit.xml pin this false so development and tests
        // never create real Jobber jobs.
        'job_create_enabled' => env('JOBBER_JOB_CREATE_ENABLED', true),
        // The fixed Jobber user every THMP job is assigned to.
        'thmp_assignee_gid' => env('JOBBER_THMP_ASSIGNEE_GID', 'Z2lkOi8vSm9iYmVyL1VzZXIvMjE1MjEwMQ=='),
    ],

];
