# TX Work Order

TX Work Order is a Laravel + Inertia/Vue application used to run TexasRenters maintenance operations. It is not just a ticket list. The app combines work-order coordination, vendor workflows, owner/tenant communication, inspections/jobs from Jobber, scheduled service, invoicing, attachments, notes, and Twilio-based messaging.

## What the app does

- Imports and manages maintenance work orders, primarily from PropertyWare.
- Organizes open work by service status and specialized queues such as inspections, lawn service, turnovers, waiting on payment, paid, and closed.
- Supports role-based views for admins, work order coordinators, vendors, owners, tenants, and accounting users.
- Tracks tasks, notes, attachments, invoices, vendor assignments, and service schedules per work order.
- Integrates Jobber jobs and visits for inspection workflows.
- Stores and surfaces messaging activity from Twilio across work orders and Jobber jobs.
- Provides building and onboarding flows, including PDF generation for onboarding and W9 packets.

## Core modules

### Work Orders

Primary operational area of the application.

- Main page: `resources/js/Pages/WorkOrder/Index.vue`
- Controller: `app/Http/Controllers/WorkOrderController.php`
- Card view by service status: `resources/js/Pages/WorkOrder/Partials/WorkOrderCard.vue`
- Detailed work order view includes:
  - details
  - tasks
  - notes
  - vendor edit
  - multiple conversation tabs by role
  - service schedule
  - attachments
  - invoices
  - general conversation thread

Special work-order queues exposed in navigation:

- Active
- Inspections
- Lawn Service
- Turnovers
- Waiting on Payment
- Paid
- Completed

### Dashboard

Provides the operational overview for most users.

- Page: `resources/js/Pages/Dashboard.vue`
- Controller: `app/Http/Controllers/DashboardController.php`

Dashboard data includes:

- total/completed/open/urgent work orders
- task completion totals
- Jobber inspection totals
- upcoming and overdue visits
- monthly growth and average completion time
- service-status totals

Accounting users do not land on the dashboard. They are redirected to the waiting-on-payment work order queue during login and dashboard access.

### Jobber Jobs / Inspections

Jobber is used for jobs, visits, and related text-message workflows.

- Page: `resources/js/Pages/Inspection/Index.vue`
- Controller: `app/Http/Controllers/InspectionController.php`
- Related routes:
  - `/inspections`
  - `/visits`
  - `/jobber-connect`
  - `/jobber-sync`
  - `/inspections/{job}/details`
  - `/inspections/text/messages`

This area supports:

- job listing by status
- job detail modal/page
- visit tracking
- client lookup from owner/tenant data
- outbound/inbound message history per job
- manual reconnect/sync to Jobber

### People and operations setup

Operational directories and setup pages include:

- Coordinators
- Vendors
- Owners
- Tenants
- Users
- WOC Numbers
- Twilio Numbers
- Task Templates
- Service Status

Navigation is built in `resources/js/Layouts/AppLayout.vue`.

### Tasks, schedules, invoices, and messages

Additional day-to-day modules:

- Tasks: `resources/js/Pages/Task/Index.vue`
- Scheduled Service calendar: `resources/js/Pages/Calendar/Index.vue`
- Invoices: `resources/js/Pages/Invoice/Index.vue`
- Conversation Logs / Messages: `resources/js/Pages/ConversationLogs.vue`
- Buildings: `resources/js/Pages/Building/*`

### Building onboarding

The app includes a public onboarding/building flow:

- Route: `/onboarding/building`
- Controller: `app/Http/Controllers/BuildingController.php`

This flow updates PropertyWare building custom fields and can dispatch PDF jobs:

- `app/Jobs/GenerateOnboardingPdfJob.php`
- `app/Jobs/GenerateW9PdfJob.php`

## Roles and access model

The application is heavily role-scoped.

Primary roles found in the codebase:

- `admin`
- `woc`
- `vendor`
- `owner`
- `tenant`
- `accounting`
- `superadmin` appears in some frontend role checks

### Role behavior highlights

- `admin`:
  Full access to most modules and admin configuration areas.

- `woc`:
  Operational coordinator access across work orders, people, communication, and scheduling.

- `vendor`:
  Sees assigned work orders, assigned tasks, relevant attachments, vendor conversations, and vendor-specific edits.

- `owner`:
  Sees owner-linked work orders, invoices, notes, attachments, and owner conversation views.

- `tenant`:
  Sees tenant-linked work orders and tenant-facing communication surfaces.

- `accounting`:
  Focused on waiting-on-payment, paid, and completed work-order queues.

### Data scoping

The main work-order scope is implemented in:

- `app/Models/Scopes/WorkOrderScope.php`

Important behavior:

- admins, WOCs, and accounting users are effectively unscoped for work orders
- vendors are limited to work orders linked through:
  - vendor assignments
  - tasks assigned to that user
  - attachments uploaded by that user
- owners are scoped by matching owner email
- tenants are scoped by `tenant_id`

Similar role-aware scoping exists for tasks, notes, invoices, attachments, conversations, and calendar data.

## Major integrations

### PropertyWare

PropertyWare is the main external maintenance/property source.

Used for:

- importing work orders
- updating work order details and statuses
- syncing vendors
- syncing invoices
- syncing service schedules
- building/onboarding data
- generating onboarding and W9 workflows

Main service:

- `app/Services/PropertyWareService.php`

### Jobber

Jobber is used for jobs, visits, and inspection-adjacent workflows.

Used for:

- syncing jobs and visits
- OAuth token management
- webhook-driven job updates
- job text conversations

Main files:

- `app/Http/Controllers/InspectionController.php`
- `app/Http/Controllers/JobberAuthController.php`
- `app/Http/Controllers/JobberWebhookController.php`
- `app/Console/Commands/ImportJobberJobs.php`
- `app/Console/Commands/RefreshJobberToken.php`

### Twilio

Twilio is used for SMS delivery and message tracking.

Used for:

- work-order messaging
- Jobber/job messaging
- delivery status callbacks
- managed phone-number sync

Main files:

- `app/Services/TwilioService.php`
- `app/Services/TwilioPhoneNumberSyncService.php`
- `app/Http/Controllers/TwilioWebhookController.php`
- `app/Http/Controllers/TwilioPhoneNumberController.php`
- `app/Http/Controllers/ImportTwilioNumberController.php`

### Asana

Asana support appears to be limited and command-driven rather than a first-class UI module.

Relevant command:

- `app/Console/Commands/UpdateTaskDueDate.php`

## Shared frontend data

Inertia shared props are defined in:

- `app/Http/Middleware/HandleInertiaRequests.php`

Important shared props:

- `auth.user`
- `twilio_phone_number`
- `maintenance_twilio_phone_number`
- `jobber_twilio_phone_number`
- `logo`
- `app_url`

Note: the code currently reads `MAINTENANC_TWILIO_PHONE_NUMBER` from the environment. That spelling is what the application expects today.

## Key routes

Public:

- `/` -> Inertia welcome page
- `/onboarding/building`
- `/conversations/{workOrder}`
- `/conversation-media/{media}` (signed)
- `/jobber/callback`
- `/jobber/reconnect`
- `/jobber/diagnose`

Authenticated areas:

- `/dashboard`
- `/work_orders/*`
- `/inspections`
- `/visits`
- `/vendors`
- `/owners`
- `/tenants`
- `/tasks`
- `/scheduled_service`
- `/conversation-logs`
- `/twilio_numbers`
- `/task_templates`
- `/service_status`
- `/buildings`

See `routes/web.php` and `routes/api.php` for the complete route surface.

## Background jobs and schedules

Scheduled tasks are defined in `routes/console.php`.

Current scheduled processes include:

- import work orders every 10 minutes
- update work-order statuses after imports
- import buildings from work orders daily
- refresh Jobber token every 30 minutes
- send job reminders daily at 4:00 PM America/Chicago
- sync Twilio phone numbers daily at 1:30 AM America/Chicago

The app also uses queued jobs for:

- syncing work-order updates
- uploading attachments
- sending Jobber text messages
- generating onboarding PDFs
- generating W9 PDFs

## Tech stack

- PHP 8.2+
- Laravel 12
- Vue 3
- Inertia.js
- Tailwind CSS
- Radix/Reka-based UI primitives
- Vite
- Laravel Jetstream / Fortify / Sanctum
- Spatie Permission
- Twilio SDK
- Jobber GraphQL/OAuth integration
- PropertyWare integration

## Local setup

### Requirements

- PHP 8.2+
- Composer
- Node.js 18+ with npm
- SQLite for quick local setup, or MySQL/MariaDB for a more production-like setup

### Install

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configure your database, then run:

```bash
php artisan migrate
php artisan db:seed
```

Build assets:

```bash
npm run build
```

### Development

Recommended all-in-one development command:

```bash
composer run dev
```

That starts:

- Laravel dev server
- queue listener
- log watcher (`pail`)
- Vite dev server

You can also run services individually:

```bash
php artisan serve
php artisan queue:listen --tries=1
php artisan pail --timeout=0
npm run dev
```

## Environment variables

### Required for local baseline

- `APP_*`
- `DB_*`
- `SESSION_*`
- `QUEUE_CONNECTION`

### PropertyWare

- `PROPERTYWARE_URL`
- `PROPERTYWARE_USERNAME`
- `PROPERTYWARE_PASSWORD`
- `PROPERTYWARE_CLIENT_ID`
- `PROPERTYWARE_CLIENT_SECRET_KEY`
- `PROPERTYWARE_SYSTEM_ID`

### Twilio

- `TWILIO_SID`
- `TWILIO_AUTH_TOKEN`
- `TWILIO_PHONE_NUMBER`
- `TWILIO_STATUS_CALLBACK_URL`
- `MAINTENANC_TWILIO_PHONE_NUMBER`

### Jobber

These are required by the codebase but are not currently present in `.env.example`.

- `JOBBER_CLIENT_ID`
- `JOBBER_SECRET`
- `JOBBER_CALLBACK_URL`
- `JOBBER_API_VERSION`

### Asana

- `ASANA_ACCESS_TOKEN`
- `ASANA_WORKSPACE_ID`
- `ASANA_USER_GID`
- `ASANA_PROJECT_IDS`
- `ASANA_WEBHOOK_URL`
- `ASANA_PROJECT_ID_L_ON_THE_MARKET`
- `ASANA_PROJECT_ID_NEW_PM_LEASE_ON_THE_MARKET`

## Important implementation notes

- The homepage is an Inertia page at `resources/js/Pages/Welcome.vue`.
- Authenticated navigation is defined centrally in `resources/js/Layouts/AppLayout.vue`.
- Accounting users are redirected away from the normal dashboard after login.
- Many pages rely on deferred Inertia props for heavier datasets.
- The current app stores a lot of operational behavior in controllers and services rather than a dedicated domain layer.

## Useful file map

- `routes/web.php`: primary web routes
- `routes/api.php`: webhook/API endpoints
- `app/Http/Controllers/`: HTTP entry points by module
- `app/Services/`: external integrations and business operations
- `app/Models/Scopes/`: role-based record visibility
- `resources/js/Layouts/AppLayout.vue`: navigation and application shell
- `resources/js/Pages/Welcome.vue`: public landing page
- `resources/js/Pages/WorkOrder/`: work-order UI
- `resources/js/Pages/Inspection/`: Jobber/job UI
- `resources/js/Pages/Building/`: building and onboarding UI

## Verification

Frontend build command:

```bash
npm run build
```

Application tests:

```bash
php artisan test
```

Formatting:

```bash
./vendor/bin/pint
```
