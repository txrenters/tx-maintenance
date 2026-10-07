<?php

return [

    'chatbot' => [
        'enabled' => env('CHATBOT_HUB_ENABLED', false),
        'url' => env('CHATBOT_HUB_URL'),
        'token' => env('CHATBOT_HUB_TOKEN'),
        'webhook_secret' => env('CHATBOT_HUB_WEBHOOK_SECRET'),
        'phone' => env('CHATBOT_HUB_PHONE'),
        'source' => 'tx-maintenance',
        // The support app account staff messages are sent as when the sender's
        // own email has no staff account there.
        'default_sender_email' => env('CHATBOT_HUB_DEFAULT_SENDER_EMAIL', 'woc@texasrenters.com'),
        // Staff whose support app account has a different email than their
        // maintenance login, keyed by the maintenance email in lower case.
        'sender_email_aliases' => [
            'xservice@txhomemp.com' => 'service@txhomemp.com',
            'woc@texasrenter.com' => 'woc@texasrenters.com',
        ],
        // Pre-production copies hold real tenant/owner numbers. In test mode
        // only the comma-separated allowlisted phones are sent to the support
        // app; an empty allowlist sends nothing. Off in production.
        'test_mode' => (bool) env('CHATBOT_HUB_TEST_MODE', false),
        'allowed_phones' => (string) env('CHATBOT_HUB_ALLOWED_PHONES', ''),
    ],

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
        // Push an owner-portal approval through to PropertyWare, so its own
        // approval queue stops asking the owner to approve the same work
        // order by email. PropertyWare records the API login as the approver
        // whoever triggered it, so the owner's name is carried in the
        // approval comment instead. Off by default: it writes to the live
        // PropertyWare record and fires PropertyWare's own "Work Order
        // Approved" alert. Set OWNER_APPROVAL_TO_PW_ENABLED=true to turn on.
        'owner_approval_push' => env('OWNER_APPROVAL_TO_PW_ENABLED', false),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        // Master outbound guard, enforced at the single send point in TwilioService.
        //
        // The per-feature flags further down decide WHICH texts a working deployment
        // sends; these two decide whether this deployment may text a real person AT ALL.
        // Getting a dozen feature flags right is not a safety mechanism -- a deployment
        // running a copy of production data needs one switch that cannot be missed.
        //
        // SMS_ENABLED=false stops everything regardless of any flag below.
        // SMS_ALLOWLIST, when set, narrows sending to exactly those numbers, which is how
        // you send yourself one real message to prove delivery without touching anyone else.
        'outbound_enabled' => env('SMS_ENABLED', true),
        'allowlist' => env('SMS_ALLOWLIST', ''),
        'status_callback_url' => env('TWILIO_STATUS_CALLBACK_URL'),
        // Sender numbers we own. `from` is the general line; `maintenance_from`
        // is the dedicated maintenance line.
        'from' => env('TWILIO_PHONE_NUMBER'),
        'maintenance_from' => env('MAINTENANC_TWILIO_PHONE_NUMBER'),
        // SMS alert to the WOC when a work order is classified as an
        // emergency. Off by default so local/testing environments never
        // text real people; set EMERGENCY_SMS_ENABLED=true in production.
        'emergency_sms' => env('EMERGENCY_SMS_ENABLED', false),
        // SMS alert to the assigned WOC when a work order description is
        // edited on the PropertyWare side after intake (e.g. a tenant adding
        // items to their request). The in-app bell alert is not gated — only
        // the text is. Off by default; set DESCRIPTION_CHANGE_SMS_ENABLED=true
        // in production to turn on texting.
        'description_change_sms' => env('DESCRIPTION_CHANGE_SMS_ENABLED', false),
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
        // sets the service appointment (details + ask them to stay reachable in
        // case additional repairs need approval during the visit).
        //
        // On by default since 2026-08-20 (wording approved): sent when a vendor
        // sets the appointment, at most once per work order — a second schedule
        // set after an estimate approval stays silent.
        'owner_schedule_sms' => env('OWNER_SCHEDULE_SMS_ENABLED', true),
        // Daily follow-up to each owner after the appointment is scheduled,
        // chasing that same question until they reply (capped). Off by default
        // until the wording is approved.
        'owner_schedule_followup_sms' => env('OWNER_SCHEDULE_FOLLOWUP_SMS_ENABLED', false),
        // Daily email to each owner while PropertyWare still shows the work
        // order as waiting on their approval. Email only: it copies
        // PropertyWare's own daily "Work Order Pending Approval" alert (no
        // cap), so that alert can be switched off there once this is on.
        // Fresh start: the deploy backfill stamps the existing backlog as
        // excluded. Off by default.
        'owner_approval_nudge' => env('OWNER_APPROVAL_NUDGE_ENABLED', false),
        // Text every property owner via the owner<->WOC conversation when a
        // new service request comes in (confirmation + description). Owners
        // with no phone are emailed instead (work_order.owner_intake_email).
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
        // Text the tenant via the tenant<->WOC conversation when the service
        // appointment is set, with the date, the vendor and their portal link.
        // Fires whoever sets it — a tenant always needs to know someone is
        // coming to their home.
        'tenant_schedule_sms' => env('TENANT_SCHEDULE_SMS_ENABLED', true),
        // Daily reminder to the tenant after the appointment is set, running
        // until the appointment date arrives or they reply (capped).
        'tenant_schedule_followup_sms' => env('TENANT_SCHEDULE_FOLLOWUP_SMS_ENABLED', true),
        // Text the tenant via the tenant<->WOC conversation when their service
        // request comes in, carrying their no-login portal link. The SMS
        // counterpart of the intake email below.
        'tenant_intake_sms' => env('TENANT_INTAKE_SMS_ENABLED', true),
        // Tenant easy fix: when a new request is one of the handbook's small
        // tenant-handled repairs (garbage disposal jammed, light bulb, smoke
        // detector battery, tripped breaker, clogged drain...), the intake
        // text and email tell the tenant how to fix it themselves, with the
        // handbook's how-to video, instead of "we received your request", the
        // owner is told we did that instead of "we will arrange the
        // estimate", the work order moves to "Checking for Tenant Easy Fix"
        // in PropertyWare and the daily check-ins follow. Off by default
        // until the wording is signed off; the verdict is still recorded on
        // every new work order while off, so it can be watched.
        'tenant_easy_fix_sms' => env('TENANT_EASY_FIX_SMS_ENABLED', false),
    ],

    'hoa' => [
        // Business days the tenant has, counted from the notice date, to
        // complete the violation items before staff are flagged to send a vendor.
        'deadline_business_days' => (int) env('HOA_DEADLINE_BUSINESS_DAYS', 5),
        // Try to create the work order in PropertyWare first; when disabled (or
        // when the create call fails) the work order is created locally only.
        'pw_create_enabled' => env('HOA_PW_CREATE_ENABLED', true),
        // PropertyWare's type and category are curated picklists that reject
        // unknown values. "HOA Violation" is now in the category picklist
        // (verified by a live create on 2026-08-04, and PW-raised work orders
        // carry it), so violations are filed under their real name. HOA
        // identity is still tracked by the HOA upload token, not these strings
        // (the PW import overwrites them on the next sync).
        'pw_category' => env('HOA_PW_CATEGORY', 'HOA Violation'),
        'pw_type' => env('HOA_PW_TYPE', 'General'),
    ],

    'tenant_portal' => [
        // The tenant portal's "Report a new issue" button: lets a tenant open a
        // brand new work order from the portal link they were already sent.
        // Ships off — the PropertyWare category below has to be proven against
        // the live picklist with one real create first (exactly how
        // HOA_PW_CATEGORY was verified on 2026-08-04).
        'create_request_enabled' => env('TENANT_PORTAL_CREATE_REQUEST_ENABLED', false),
        // Create the request in PropertyWare first; when disabled (or when the
        // create call fails) it is captured as a local-only work order so the
        // tenant's words and photos are never lost.
        'pw_create_enabled' => env('TENANT_PORTAL_PW_CREATE_ENABLED', true),
        // PropertyWare's type and category are curated picklists that reject
        // unknown values. Both defaults are the values PropertyWare itself uses
        // most on imported work orders ("General Maintenance" on 15k+ of them,
        // "Service Request" on 19k+), so they are known-good rather than
        // plausible — "Repair" and "Maintenance" look right but appear only on
        // rows the test factory made, and would fail the create exactly the way
        // the HOA category did before 2026-08-04.
        //
        // Fixed values, deliberately NOT the source work order's: copying those
        // would misroute the AI vendor recommendation, and when the source is a
        // turnover, re-key, cleaning or HOA work order,
        // skipsAutomatedMessages()/isHoaViolation() would silently suppress
        // every tenant and owner intake message on a request the tenant is
        // waiting to hear about. Never set these to Turnover, Re-Key, Cleaning,
        // Make ready or HOA Violation.
        'pw_category' => env('TENANT_PORTAL_PW_CATEGORY', 'General Maintenance'),
        'pw_type' => env('TENANT_PORTAL_PW_TYPE', 'Service Request'),
        // Anti-spam: minutes before the same portal link may open another
        // request, and the ceiling on requests opened for one property in 24h.
        'request_cooldown_minutes' => (int) env('TENANT_PORTAL_REQUEST_COOLDOWN_MINUTES', 10),
        'max_open_requests' => (int) env('TENANT_PORTAL_MAX_OPEN_REQUESTS', 5),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5.1-mini'),
    ],

    'work_order' => [
        // Email the tenant a branded confirmation when their service request
        // comes in, carrying the no-login portal link. Not a Twilio channel, so
        // it lives here rather than under `twilio`.
        'tenant_intake_email' => env('TENANT_INTAKE_EMAIL_ENABLED', true),
        // The owner intake notice's email fallback: an owner with no phone on
        // file is emailed the same "new service request" / "created by our
        // team" notice, with their portal link, instead of being left silent.
        // Rides the owner_service_request_sms gate above; this switch only
        // turns the email half off.
        'owner_intake_email' => env('OWNER_INTAKE_EMAIL_ENABLED', true),
        // Repeat work orders (same issue type + same building) no longer assign
        // the prior vendor automatically — they only surface that vendor as the
        // recommendation, for staff to assign by hand. Nothing reads this gate
        // any more; it is kept so an existing VENDOR_AUTO_ASSIGN_ENABLED in a
        // deployed .env stays harmless.
        'auto_assign_vendor' => false,
        // Logins a whole crew shares, by email: the note dialog makes them
        // pick their name from the technician roster before a note is saved,
        // exactly as it does for the THMP vendor login. THMP's field crew
        // works from a staff-type login, which no vendor rule can recognise.
        // Comma-separated; case and surrounding spaces are ignored.
        'note_technician_logins' => env('NOTE_TECHNICIAN_LOGINS', 'thmp@texasrenters.com'),
    ],

    'microsoft' => [
        'tenant_id' => env('MICROSOFT_TENANT_ID'),
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'mailbox' => env('MICROSOFT_MAILBOX', 'workorders@texasrenters.com'),
        'job_reminder_mailbox' => env('MICROSOFT_JOB_REMINDER_MAILBOX', 'service@txhomemp.com'),
        'turnover_mailbox' => env('MICROSOFT_TURNOVER_MAILBOX', 'thmp@texasrenters.com'),
        'invoices_mailbox' => env('MICROSOFT_INVOICES_MAILBOX', 'invoices@texasrenters.com'),
    ],

    'invoices_mailbox' => [
        // Vendors keep emailing invoices to invoices@ instead of uploading them
        // in the portal. When this is on, every vendor-looking invoice email
        // gets an automatic reply from that same mailbox pointing at the
        // vendor's no-login portal dashboard. Off by default: the first run
        // only looks back one hour, but the mailbox must be reachable by the
        // Graph app registration before it is switched on.
        'auto_reply_enabled' => env('INVOICES_MAILBOX_AUTO_REPLY_ENABLED', false),
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

    'invoices' => [
        // Reads the vendor's invoice number off a PHOTO or scanned PDF with
        // the OpenAI vision model when the uploader left it blank. PDFs with
        // a text layer are read locally for free and never reach the model.
        // Needs an OpenAI key (env or the AI Settings page); a cheap model is
        // enough for one printed number.
        'number_vision_enabled' => env('INVOICE_NUMBER_VISION', true),
        'number_vision_model' => env('INVOICE_NUMBER_VISION_MODEL', 'gpt-5-mini'),
    ],

    'jobber' => [
        'graphql_url' => env('JOBBER_GRAPHQL_URL', 'https://api.getjobber.com/api/graphql'),
        'api_version' => env('JOBBER_API_VERSION'),
        'client_id' => env('JOBBER_CLIENT_ID'),
        'client_secret' => env('JOBBER_SECRET'),
        'callback_url' => env('JOBBER_CALLBACK_URL'),
        // When THMP is assigned to a work order, create the matching job in
        // Jobber and store its link on the work order. ON by default so a
        // production deploy creates jobs immediately (the n8n "Create Job"
        // workflow that used to do this is disabled — this app owns it now).
        // Local .env and phpunit.xml pin this false so development and tests
        // never create real Jobber jobs.
        'job_create_enabled' => env('JOBBER_JOB_CREATE_ENABLED', true),
        // The fixed Jobber user every THMP job is assigned to.
        'thmp_assignee_gid' => env('JOBBER_THMP_ASSIGNEE_GID', 'Z2lkOi8vSm9iYmVyL1VzZXIvMjE1MjEwMQ=='),
        // Lets staff assign an outside vendor to a Jobber job. On by default:
        // it only writes a local pivot row and sends nothing outward.
        'vendor_assign_enabled' => env('JOBBER_VENDOR_ASSIGN_ENABLED', true),
        // The assignment email + SMS to that vendor. ON by default after the
        // dry run — assigning a vendor now messages them. Local .env and
        // phpunit.xml pin this false so development and tests never message a
        // real vendor.
        'vendor_notify_enabled' => env('JOBBER_VENDOR_NOTIFY_ENABLED', true),
        // Staff tag THMP onto Jimmie Gendke SFA's work orders only so this app
        // creates the Jobber job; the work is SFA's. Work orders carrying any
        // of these vendors are hidden when a board's Vendor filter is THMP.
        // Keyed on PropertyWare vendor ids because the vendors table names a
        // row after PropertyWare's COMPANY name (import:all-vendors), which is
        // "Jimmie" for this vendor (it read "THMP" until 2026-09-12) and can
        // be edited in PropertyWare at any time; the id cannot. Comma-separated.
        'thmp_filter_hidden_vendor_ids' => env('JOBBER_THMP_FILTER_HIDDEN_VENDOR_IDS', '4802084865'),
        // Read the THMP crew's notes, and the photos on them, off the Jobber
        // job and show them on the work order's Notes tab. OFF by default:
        // reading notes may need an OAuth scope this app's Jobber connection
        // was never granted, and adding a scope forces every connected account
        // to re-authorize by hand. Turn this on only after
        // `jobber:sync-job-notes --work-order=<id>` has been run once against
        // a real THMP work order and come back with notes.
        'note_sync_enabled' => env('JOBBER_NOTE_SYNC_ENABLED', false),
        // Exact vendor names to hide as well, for a vendor with no stable id
        // to hand. Comma-separated; case and surrounding spaces are ignored.
        // The rule is off only when this AND the id list are both blank.
        'thmp_filter_hidden_vendors' => env('JOBBER_THMP_FILTER_HIDDEN_VENDORS', ''),
        // The logins that rule applies to, by email. Deliberately not global:
        // WOC staff must still find SFA's work orders under the THMP filter
        // to process them and message the tenant, so only THMP's own login
        // (John Carlo) gets the trimmed view. Comma-separated; blank = nobody.
        'thmp_filter_users' => env('JOBBER_THMP_FILTER_USERS', 'xservice@txhomemp.com'),
        // The Jobber client every Crystal Creek Air customer's property is
        // filed under ("Crystal Creek Air, LLC"). Blank = look it up by name
        // in the jobber_clients mirror the webhooks keep.
        'crystal_creek_client_gid' => env('JOBBER_CRYSTAL_CREEK_CLIENT_GID'),
    ],

    'crystal_creek' => [
        // Crystal Creek Air work orders (outside customers, never in
        // PropertyWare) get their own number series, counting from 1 (Earl
        // 10-08: "outside texasrenter property should start at 0 counter").
        // The allocator steps over any number a PropertyWare work order
        // already holds, so the two series never share a number.
        'first_work_order_no' => (int) env('CRYSTAL_CREEK_FIRST_WORK_ORDER_NO', 1),
        // The category a Jobber job made by hand under the Crystal Creek Air
        // client gets when it is imported as a work order; staff can change it.
        'default_category' => env('CRYSTAL_CREEK_DEFAULT_CATEGORY', 'HVAC'),
    ],

    'inbox' => [
        // AI filter that drops pure courtesy closers ("thank you", "ok
        // great") out of the awaiting-reply badge, Inbox counts, board
        // summary and unanswered report. Verdicts are cached message ids, so
        // switching this off restores the full counts immediately without
        // losing them. The filter fails open: an unjudged thread, AI outage
        // or emptied cache just means the thread keeps counting as awaiting.
        // Internal-only — nothing here ever messages a tenant, owner or
        // vendor.
        'courtesy_filter' => env('COURTESY_CLOSER_FILTER_ENABLED', true),

        // AI triage that tags each thread's newest inbound message with an
        // intent (reschedule request, complaint, access issue, job done,
        // question) and extracts concretely proposed appointment times as
        // suggestions staff accept or dismiss. Read-only and fail-open like
        // the courtesy filter: nothing here ever messages anyone, and an
        // unjudged thread simply shows no chip.
        'intent_triage' => env('INTENT_TRIAGE_ENABLED', true),
    ],

    'ai' => [
        // Vision check that a vendor's "after" photo plausibly shows the
        // reported issue addressed — a read-only flag on the Attachments tab,
        // never a block on the upload. Fails open: no review, no flag.
        'photo_review' => env('PHOTO_REVIEW_ENABLED', true),

        // On-demand "why is this stuck" summaries on the open-over-30 report.
        'stale_digest' => env('STALE_DIGEST_ENABLED', true),

        // The AI confirms the keyword shortlist before a work order is tagged
        // a tenant easy fix (and the tenant texted a how-to). Off = the
        // keywords decide alone, the pre-2026-10-02 behavior. An AI error or a
        // verdict below the confidence bar means "not an easy fix".
        'easy_fix_judge' => env('TENANT_EASY_FIX_AI_JUDGE_ENABLED', true),
        'easy_fix_min_confidence' => (int) env('TENANT_EASY_FIX_AI_MIN_CONFIDENCE', 70),
    ],

    'hvac_board' => [
        // The "new activity" counters on the HVAC board (badge, bell, dismiss,
        // Mark all seen). Every staff login gets them since 2026-10-01; this
        // switch turns them off for everyone without a deploy. Replaces the
        // old HVAC_BOARD_BADGE_EMAILS allow-list, which is no longer read.
        'badges_enabled' => (bool) env('HVAC_BOARD_BADGES_ENABLED', true),
    ],

    'easy_fix_board' => [
        // The same counters on the Tenant Easy Fix board.
        'badges_enabled' => (bool) env('EASY_FIX_BOARD_BADGES_ENABLED', true),
    ],

];
