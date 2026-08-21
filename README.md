# TX Work Order — System Documentation

A Laravel + Vue (Inertia) **work order / maintenance management system** for a property-management company (TexasRenters). It is the operational hub that ties together the company's property-management system of record (**PropertyWare**), SMS messaging (**Twilio**), field-service scheduling (**Jobber**), and an AI-assisted vendor-recommendation workflow.

This README is written for **IT / engineering staff** who are new to the system. It documents the architecture, data model, integrations, routes, background processing, and how to run/operate the application.

> **🌐 Live site:** <https://txrenters.azurewebsites.net> (hosted on Azure App Service)

---

## Table of Contents

1. [What the System Does](#1-what-the-system-does)
2. [Tech Stack](#2-tech-stack)
3. [Local Setup & Running](#3-local-setup--running)
4. [Environment Variables](#4-environment-variables)
5. [Data Model](#5-data-model)
6. [Roles, Permissions & Access Control](#6-roles-permissions--access-control)
7. [Application Routes](#7-application-routes)
8. [External Integrations](#8-external-integrations)
9. [Background Jobs, Commands & Scheduling](#9-background-jobs-commands--scheduling)
10. [Frontend Architecture](#10-frontend-architecture)
11. [Operational Runbook](#11-operational-runbook)

---

## 1. What the System Does

The central entity is the **Work Order** — a maintenance request tied to a property (Building), an Owner, a Tenant, and one or more Vendors. Work orders originate in **PropertyWare** and are imported on a schedule. Internal staff called **WOCs** (Work Order Coordinators) progress each work order through a configurable status workflow, assign vendors, schedule service, exchange SMS with all parties, collect invoices, and close the order back in PropertyWare.

Key capabilities:

- **PropertyWare sync** — imports work orders, buildings, owners, and vendors; pushes vendor assignments, estimates, schedules, status changes, approvals, documents, and invoices back.
- **Work-order queues** — organizes open work by service status and specialized queues: inspections, lawn service, turnovers, waiting on payment, paid, and closed.
- **Two-way SMS/MMS (Twilio)** — threaded conversations between staff and tenants/owners/vendors, matched back to the right work order.
- **Vendor portal** — passwordless, token-based dashboards where vendors view assignments, submit estimates, upload attachments/invoices, schedule visits, and message coordinators.
- **Jobber integration** — syncs field-service jobs/visits ("inspections") and texts clients reminders.
- **AI vendor recommendation** — classifies a work order's issue and recommends a vendor (with deterministic heuristic fallback).
- **Calendar, KPI reports, dashboards, task automation, building onboarding** (incl. onboarding & W-9 PDF generation) — operational tooling for the coordination team.

---

## 2. Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13.x (composer requires `^13.0`), PHP 8.4 |
| Frontend | Vue 3 + Inertia.js v2 (SPA, no separate API client for the main app) |
| UI | Reka UI / Radix Vue (shadcn-vue-style design system), Tailwind CSS 3 |
| Auth | Laravel Jetstream + Fortify (2FA capable), Sanctum tokens |
| Permissions | Spatie laravel-permission |
| Realtime | Laravel Reverb (WebSockets) + Laravel Echo |
| Database | MySQL (production), SQLite (local dev) |
| Queue / cache | Redis (predis) — configurable |
| AI | `laravel/ai` (OpenAI / Azure OpenAI, with others available) |
| PDF | `barryvdh/laravel-dompdf`, `mikehaertl/php-pdftk`, FPDF/FPDI |
| Other | PhpSpreadsheet (exports), Spatie activitylog, Spatie image-optimizer, Ziggy (named routes in JS) |
| Local dev env | Laravel Sail (Docker) |

---

## 3. Local Setup & Running

This project is configured to run inside **Laravel Sail** (Docker). Prefix PHP/Artisan/Composer/Node commands with `vendor/bin/sail`.

```bash
# First-time setup
cp .env.example .env
vendor/bin/sail up -d
vendor/bin/sail composer install
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate --seed     # seeds roles & permissions
vendor/bin/sail npm install

# Day-to-day development (server + queue + logs + vite together)
vendor/bin/sail composer run dev

# Or run pieces individually
vendor/bin/sail artisan serve
vendor/bin/sail artisan queue:listen
vendor/bin/sail artisan pail               # live logs
vendor/bin/sail npm run dev                # Vite dev server
vendor/bin/sail artisan reverb:start       # WebSocket server (for realtime)
```

### Build & test

```bash
vendor/bin/sail npm run build              # production assets
vendor/bin/sail artisan test --compact     # run test suite
vendor/bin/sail artisan test --filter=Name # single test
vendor/bin/sail bin pint                   # PHP code style (run before committing)
```

> **Vite manifest errors** ("Unable to locate file in Vite manifest") mean assets aren't built — run `npm run dev` or `npm run build`.

---

## 4. Environment Variables

Beyond the standard Laravel keys (`APP_*`, `DB_*`, `MAIL_*`, `SESSION_*`, `CACHE_*`, `REDIS_*`), the following service-specific vars must be populated per environment. They are surfaced through `config/services.php`, `config/ai.php`, `config/broadcasting.php`, and `config/filesystems.php`.

| Integration | Env vars |
|---|---|
| **Twilio** | `TWILIO_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_PHONE_NUMBER`, `TWILIO_STATUS_CALLBACK_URL`, `MAINTENANC_TWILIO_PHONE_NUMBER` |
| **PropertyWare** | `PROPERTYWARE_URL` (SOAP WSDL base), `PROPERTYWARE_USERNAME`, `PROPERTYWARE_PASSWORD`, `PROPERTYWARE_CLIENT_ID`, `PROPERTYWARE_CLIENT_SECRET_KEY`, `PROPERTYWARE_SYSTEM_ID` |
| **Jobber** | `JOBBER_CLIENT_ID`, `JOBBER_SECRET` (also the webhook HMAC key), `JOBBER_CALLBACK_URL`, `JOBBER_API_VERSION` |
| **Asana** | `ASANA_ACCESS_TOKEN`, `ASANA_PROJECT_ID_L_ON_THE_MARKET`, `ASANA_PROJECT_ID_NEW_PM_LEASE_ON_THE_MARKET` (others present but largely unused) |
| **AWS S3 / SES** | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `FILESYSTEM_DISK` (set to `s3` to make S3 the default disk) |
| **Reverb (WebSockets)** | `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, plus `VITE_REVERB_*` (exposed to the frontend) |
| **AI** | `AI_DEFAULT_PROVIDER` (auto-selects Azure if `AZURE_OPENAI_*` set, else OpenAI), `OPENAI_API_KEY`, `OPENAI_MODEL` (default `gpt-5.1-mini`), or `AZURE_OPENAI_API_KEY` + `AZURE_OPENAI_URL` + `AZURE_OPENAI_DEPLOYMENT` |

> Read the real `.env` for current values; never commit secrets. Verify the configured AI model id against currently available models before deploying.

---

## 5. Data Model

> Most models use `$guarded = []` (all attributes mass-assignable). Phone numbers on `User`/`Owner`/`Tenants` are normalized to E.164 (`+1…`) via accessors/mutators. **Several models are filtered per logged-in user via global scopes** — see [Access Control](#6-roles-permissions--access-control).

### Entity relationship overview

```
User (Spatie roles: admin, woc, accounting, vendor, owner, tenant)
 ├─ hasOne/hasMany → Vendor, Tenants, Owner          (a login may map to a domain party)
 ├─ hasMany       → WorkOrder (as assigned WOC, FK user_id)
 └─ hasMany       → WOCNumbers → TwilioPhoneNumber   (which Twilio line a coordinator texts from)

Building (one per PropertyWare property)
 └─ hasMany → WorkOrder  (joined on building_id → buildings.propertyware_id)

WorkOrder  (the hub)
 ├─ belongsTo     → User (woc), ServiceStatus, Owner (managed_by), Tenants (requested_by), Building
 ├─ belongsToMany → Owner (work_order_owners), Tenants (work_order_tenants)
 ├─ belongsToMany → Vendor (work_order_vendors pivot = WorkOrderVendor: cost/time estimates + access_token)
 ├─ hasMany       → WorkOrderTask, WorkOrderNotes, Conversation (by type), ServiceSchedule, Attachments, Invoice
 └─ hasOne        → Invoice (latest), WorkOrderRecommendation (latestOfMany)

Conversation (work_order_conversations) → hasMany ConversationMedia
ServiceStatus → hasMany WorkOrder   (the workflow stage)
TaskTemplate → hasMany Task → hasMany TaskDetail   (task automation library)
Vendor → hasMany Invoice, FallbackVendor, ServiceSchedule

Jobber (jobber_jobs) ── belongsTo → JobberClient, JobberProperty
 ├─ hasMany → JobberVisit, JobberTextMessage, ClientContact
JobberToken (OAuth token storage, standalone)
```

### Core domain entities

- **WorkOrder** (`work_orders`) — A single maintenance request; the hub of the system. Globally scoped by `WorkOrderScope` (role-based visibility). Notable fields: `work_order_no`, `propertyware_id`, `status`/`local_status` (default `Created`), `priority`/`priority_as_int`, `is_emergency`, `is_single_vendor`, `cost_estimate`, `total_cost`, `category`, `location`/`specific_location`, `description`, `created_date`, `scheduled_end_date`, `skip_automated_tasks`, `service_request_sent_at`, and `service_request_*` contact snapshot fields. Conversations are split by `conversation_type` into typed relations (`tenant_conversation()`, `owner_conversation()`, `vendor_conversation()`, `vendor_tenant_conversation()`, `vendor_owner_conversation()`). **Joins to Building on `propertyware_id`, not the local primary key.**
- **Building** (`buildings`) — A PropertyWare property, keyed by unique `propertyware_id`. Carries maintenance policy fields: `maintenance_notice`, `maintenance_spending_limit_amount`/`_time`, `maintenance_labor_surcharge_amount`/`_type`, `category`, `property_type`, `custom_fields` (array), `details_synced_at`.
- **Owner** (`owners`) — Property owner. `belongsTo User`, `belongsToMany WorkOrder`. Phone/mobile normalized to E.164.
- **Tenants** (`tenants`) — Occupant who requests work. `belongsTo User`, `belongsToMany WorkOrder`.
- **Vendor** (`vendors`) — Contractor/service company, keyed by unique `propertyware_id`. Fields: `name`, `email`, `vendor_type`, `twilio_number`, `is_active`, `user_id`, `zones` (array), `portal_token`. Has passwordless portal access via `ensurePortalToken()` (stable 48-char `portal_token`). `getPhoneAttribute()` proxies to the linked user.
- **VendorTypes** (`vendor_types`) — Lookup of vendor categories.
- **FallbackVendor** (`fallback_vendors`) — Backstop vendors for the AI recommendation flow. Fields: `vendor_id`, `name`, `contacts`/`issue_types`/`keywords` (arrays), `priority` (default 50), `is_active`. Scope: `scopeActive`.

### Task / workflow automation

- **ServiceStatus** (`service_status`) — A stage in the work-order lifecycle (workflow state machine).
- **TaskTemplate** (`task_templates`) — Reusable checklist tied to status transitions; `belongsTo ServiceStatus` twice (`currentServiceStatus`, `nextServiceStatus`).
- **Task** (`tasks`) — A template task item with `type` enum (`Vendor`/`Woc`), `is_mandatory`/`is_optional`/`is_emergency`, plus Yes/No branch helpers (`taskDetailYesOption`/`taskDetailNoOption`).
- **TaskDetail** (`task_details`) — Conditional Yes/No follow-up for a Task.
- **WorkOrderTask** (`work_order_tasks`) — A concrete task instance on a work order. Uses **SoftDeletes**, globally scoped by `TaskScope`. Fields: `description`, `due_date`, `option` (`Yes`/`No`), `status` (`pending`/`processing`/`completed`), `assigned_user_id`.

### Communications, documents, scheduling

- **Conversation** (`work_order_conversations`) — A single SMS message in a work-order thread. Scoped by `ConversationScope`. Fields: `message`, `conversation_type` (tenant/owner/vendor/vendor_tenant/vendor_owner), `sender_number`, `receiver_number`, read flags, `vendor_id`, Twilio delivery-status fields.
- **ConversationMedia** (`work_order_conversation_medias`) — MMS attachment for a message; appends a signed `public_url`.
- **WorkOrderNotes** (`work_order_notes`) — Free-text notes / vendor notes.
- **WorkOrderDocuments** (`work_order_documents`) — Document records on work orders.
- **Attachments** (`attachments`) — File uploads; scoped by `AttachmentScope`; appends `attachment_url`.
- **Invoice** (`invoices`) — Vendor invoice for a work order. Scoped by `InvoiceScope`. Fields: `title`, `filename`, `filetype`, `amount`, `status` (`pending`/`approved`/`decline`), `remarks`, `publish` flag. Appends `invoice_url`.
- **ServiceSchedule** (`service_schedules`) — A scheduled service appointment / calendar entry. Scoped by `CalendarScope`. Fields: `title`, `description`, `scheduled_date`, `scheduled_end_date`, `status` (`scheduled`/`cancelled`/`completed`).
- **WorkOrderRecommendation** (`work_order_recommendations`) — AI/heuristic vendor recommendation (one per work order). Fields: `status` (default `generated`), `source` (`heuristic`/AI), `model`, `issue_type`/`issue_subtype`, `vendor_category`, `confidence`, `needs_human_review`, `summary`, `reasoning`, and JSON arrays `keywords`/`matched_work_orders`/`alternate_vendors`/`classification`/`raw_response`.

### Twilio messaging infrastructure

- **WorkOrderVendor** (`work_order_vendors`) — Pivot for WorkOrder↔Vendor. Pivot fields: `cost_estimate`, `time_estimate`, `scheduled_end_date`, `access_token` (48-char per-assignment passwordless token).
- **WOCNumbers** (`woc_numbers`) — Maps a coordinator (User) to the Twilio number they send from.
- **TwilioPhoneNumber** (`twilio_phone_numbers`) — A provisioned Twilio line (`name`, `phone_number`).

### Jobber integration

- **Jobber** (`jobber_jobs`) — A job synced from Jobber, keyed by unique `jobber_id`. Relations: `client`, `property`, `visits`, `textMessages`, `clientContacts`.
- **JobberClient** / **JobberProperty** — Customer & property records from Jobber.
- **JobberVisit** (`jobber_visits`) — Scheduled visit; scope `scopeTbp` filters Tenant Benefit Package visits.
- **JobberTextMessage** — SMS tied to a Jobber job/visit.
- **JobberToken** — Stores Jobber OAuth credentials (single row).
- **ClientContact** — Contact persons for a Jobber client.

### Cross-cutting notes

- **Activity logging** (Spatie Activitylog) is called explicitly via `activity()` in controllers/services (not via a model trait). Table: `activity_log`.
- **Soft deletes** are used only on `WorkOrderTask`.
- **Global scopes** silently filter `WorkOrder`, `WorkOrderTask`, `Conversation`, `ServiceSchedule`, `Invoice`, `Attachments` per logged-in user/role — important when debugging "missing records" in tinker or jobs.
- **External keys**: Building/Vendor/WorkOrder tie to PropertyWare via `propertyware_id`; Jobber models use `jobber_id`.

---

## 6. Roles, Permissions & Access Control

Authentication is **Jetstream + Fortify** (email/password, optional 2FA, Sanctum tokens). Roles/permissions use **Spatie laravel-permission**. Roles are seeded in `database/seeders/RolesAndPermissionsSeeder.php` and `AccountingRoleSeeder.php`.

| Role | Scope |
|---|---|
| **admin** | Full access; bypasses all gates and the `WorkOrderScope`. |
| **woc** | Work Order Coordinator (core internal staff); view/create/edit, sees all work orders. |
| **accounting** | Finance/invoices; sees all work orders, lands on the "waiting on payment" page after login. |
| **vendor** | Contractor; sees only work orders they are assigned to / have tasks or attachments on. |
| **owner** | Property owner; sees work orders for owners sharing their email. |
| **tenant** | Occupant; sees only their own work orders. |

Authorization works in **three layers**:

1. **Global admin override** — `AppServiceProvider::boot()` registers `Gate::before(... hasRole('admin') ...)`, so admins bypass all gates/policies.
2. **Policies** (`app/Policies/`) invoked via `Gate::authorize(...)` with custom ability names (e.g. `view_vendors`, `create_vendor`, `update_user`, `delete_user`). Policies: `VendorPolicy`, `UserPolicy`, `OwnerPolicy`, `TenantsPolicy`, `ServiceStatusPolicy`, `TaskTemplatePolicy`, `TwilioPhoneNumberPolicy`, `WOCNumbersPolicy`, `FallbackVendorPolicy`.
3. **Row-level scoping** via global Eloquent scopes (`app/Models/Scopes/`) — `WorkOrderScope` and siblings restrict which rows each role can query.

### Vendor portal (passwordless)

Two custom magic-link middlewares (aliased in `bootstrap/app.php`) gate the external vendor portal **with no login** — access is by possession of an unguessable token:

- `vendor.account` → `ResolveVendorAccountToken`: resolves `Vendor.portal_token` (full dashboard of all the vendor's active work orders). Bad token → 404.
- `vendor.portal` → `ResolveVendorPortalToken`: resolves a single `WorkOrderVendor.access_token` (one work-order assignment). Bad token → 404.

---

## 7. Application Routes

Routes live in `routes/web.php` (session-authenticated Inertia pages + most AJAX), `routes/api.php` (stateless AJAX + external webhooks), and `routes/channels.php` (broadcast channels). The main authenticated area is gated by `['auth:sanctum', config('jetstream.auth_session'), 'verified']`.

### Web routes (authenticated)

| Area | Controller | Purpose |
|---|---|---|
| Dashboard | `DashboardController` | KPI stat cards & charts (`accounting` role redirects to payments) |
| Work orders | `WorkOrderController` | Core entity + filtered lists: closed, waiting-on-payment, paid, inspections, lawn service, turnovers; details, report, close, open, emergency/vendor change, import, export |
| WO recommendations | `WorkOrderRecommendationController` | AI/generated vendor recommendations |
| Coordinators | `CoordinatorController` | Assign coordinators to work orders |
| Buildings | `BuildingController` | Building list/detail/create + onboarding |
| Owners / Tenants / Vendors / Users | respective controllers | CRUD (policy-gated) |
| Vendors | `VendorController` | CRUD, import, status change, PropertyWare/Jobber sync |
| Service status / Task templates / Tasks | respective controllers | Workflow & task automation config |
| Inspections (Jobber) | `InspectionController`, `InspectionVisitController` | Jobber jobs/visits, `jobber-connect`, `jobber-sync` |
| Jobber text msgs | `JobberTextMessageController` | SMS tied to Jobber jobs |
| Conversations / SMS | `ConversationController`, `ConversationLogsController`, `TwilioMessageSearchController` | Threads, sending, search/status sync |
| Calendar | `CalendarController` | Scheduled-service calendar |
| Reports | `ReportController` | unresolved-7-days, not-scheduled-3-days, tasks-on-time, open-over-30-days |
| Invoices / Attachments / Notes | `InvoiceController`, `API\*`, `WorkOrderNotesController`, `VendorNotesController` | Per-work-order documents |
| Twilio / WOC numbers / Fallback vendors | respective controllers | Messaging config & vendor routing |
| Notifications | `NotificationController` | In-app notifications |

### Public web routes

| Route | Middleware | Purpose |
|---|---|---|
| `GET /` | none | Welcome/landing page |
| `GET /vendor/{vendorToken}` | `vendor.account` | Token-gated vendor dashboard |
| `vendor-portal/{token}/*` | `vendor.portal` | Token-gated single-WO portal (estimate, attachments, invoice, schedule, message, task complete) |
| `GET /onboarding/building` | none | **Building onboarding form** (public, no login) — see [Building Onboarding](#building-onboarding-public-self-service-form) below |
| `GET /conversation-media/{media}` | `signed` | Signed-URL media access |
| `GET /jobber/callback`, `/jobber/reconnect`, `/jobber/diagnose` | none | Jobber OAuth & diagnostics |

### Building Onboarding (public self-service form)

A **public, unauthenticated** form that lets a new property owner onboard their building. Share this link directly with owners:

```
{APP_URL}/onboarding/building
```

For example, in production: <https://txrenters.azurewebsites.net/onboarding/building> (locally it is `http://localhost:8080/onboarding/building`).

**Route:** `GET /onboarding/building` → `BuildingController@create` (route name `building.create`), renders the `Building/Create` Vue page. No login or token is required — the URL itself is the entry point.

**What the flow does:**

1. **Find the building** — the owner enters their property name, full name, and contact number. The form calls `POST /search-building` (`BuildingController@searchBuilding`), which looks the property up in PropertyWare and matches on name + owner + phone.
2. **Complete onboarding details** — the owner fills in maintenance preferences, pet fields, optional W-9 entity/business details, and **signs on-screen** (signature capture).
3. **Submit** — the form calls `POST /buildings/{buildingId}/update-custom-fields` (`BuildingController@updateCustomFields`), which writes the custom fields, maintenance notice, and pet fields back to **PropertyWare**.
4. **PDF generation** — on success it dispatches `GenerateOnboardingPdfJob` (the signed onboarding packet) and, if a W-9 entity/business name was provided, `GenerateW9PdfJob` — both run on the queue, so a **queue worker must be running**.

> Because the page is public, only share the link with the intended owner. The submit step writes to PropertyWare, so verify PropertyWare credentials are configured in the target environment before sending the link.

### Webhook routes (`routes/api.php`)

| Webhook | Route | Protection | Handler |
|---|---|---|---|
| Twilio inbound SMS | `POST /api/twilio/webhook` | throttle 60/min | `TwilioWebhookController@handle` |
| Twilio status callback | `POST /api/twilio/status-callback` | throttle 120/min | `TwilioWebhookController@statusCallback` |
| TEX app inbound SMS | `POST /api/v1/tex/webhook` | throttle 60/min | `TwilioWebhookController@handle` |
| Jobber events | `POST /api/jobber/webhook` | throttle 60/min + **HMAC-SHA256** (`JOBBER_SECRET`) | `JobberWebhookController@handle` |

> Most other `api.php` routes are **not** behind `auth:sanctum`; they share the SPA's session/CSRF context. Only `GET /api/user` requires Sanctum.

### Broadcast channels (`routes/channels.php`)

- `App.Models.User.{id}` — private per-user channel (authorized only if `$user->id === $id`).
- `jobs`, `workOrders` — open to any authenticated user (power live list updates).

---

## 8. External Integrations

### Twilio (SMS / MMS)

- **`TwilioService`** wraps the Twilio SDK. `sendMessage()` queues outbound SMS (media URLs are appended to the body as text links, not true MMS) and attaches a status-callback URL.
- **`InboundTwilioMessageProcessor::process()`** handles inbound webhook payloads: dedupes by `MessageSid`, **forwards every inbound message to a PlusThis marketing webhook** (hardcoded `https://e.plusthis.com/webhooks/Twilio/sms/19802`), matches to a work-order thread or a Jobber thread, downloads MMS media via `MediaService`, and filters internal mirror echoes.
- **Inbound thread routing** (`resolveInboundThread()`) decides which work order a reply belongs to. The outbound "from" number belongs to a coordinator rather than to a work order, so a tenant with two open work orders under the same coordinator has an identical phone pair on both. Tiers, most to least certain: an explicit `(Ref: WO#<n>)` in the body (matched against `work_order_no`, then the id for unnumbered work orders, with the thread taken from the phone pair or the work order's own tenant/owner/vendor); the last message **we sent** that person about an **open** work order; the same allowing closed ones; any message on the pair in either direction; then the original exact-string pair match, so nothing that routes today starts being dropped. Numbers are compared on their last ten digits with punctuation stripped. Routing is anchored on outbound messages so one misrouted reply cannot pull later replies onto the same wrong work order, and an ambiguous phone pair logs a warning naming every candidate work order.
- **`TwilioPhoneNumberSyncService::sync()`** upserts the local Twilio number inventory.
- Status callbacks update delivery status / error codes on `Conversation` and `JobberTextMessage`.

### PropertyWare (system of record)

Dual transport:
- **REST** (`https://api.propertyware.com/pw/api/rest/v1/...`) with `x-propertyware-client-id` / `-client-secret` / `-system-id` headers — reads + PATCH updates + custom-field PUTs + document/invoice uploads.
- **SOAP** (`{PROPERTYWARE_URL}?wsdl`, TLS 1.2) — for operations REST can't do: vendor assignment, full work-order detail updates, notes, document attach, approval.

Key class: **`PropertyWareService`** (reads: `getWorkOrders`, `getWorkOrdersViaRestAPI`, `getBuilding`, `getOwners`, `getVendors`, document downloads; writes: `updateWorkOrder`, `changeWorkOrderVendors`, `updateWorkOrderServiceSchedule`, `closeWorkOrder`, `reOpenWorkOrder`, `approvedWorkOrder`, `uploadVendorAttachment`, `uploadVendorInvoice`, `addVendorNotes`).

> **Outbound side-effect**: when vendor "Texas Home Maintenance Pros" is assigned, `changeWorkOrderVendors()` POSTs to an **n8n webhook** (`https://n8n.srv902502.hstgr.cloud/webhook/create-job`) to create a matching Jobber job. (Hardcoded URL.)

### Jobber (field-service scheduling)

OAuth 2.0 with refresh tokens stored in `jobber_tokens`:
1. `GET /jobber-connect` → redirect to Jobber authorize.
2. `GET /jobber/callback` → exchange code, store token.
3. `ensureValidToken()` refreshes within 5 min of expiry; `jobber:refresh-token` runs every 30 min.

Data via GraphQL (`POST https://api.getjobber.com/api/graphql`, `X-JOBBER-GRAPHQL-VERSION` header). The webhook (`/api/jobber/webhook`, HMAC-verified) dispatches `JOB_*` and `VISIT_*` topics to upsert local Jobber records. `JobberDiagnosticController` (`/jobber/diagnose`, `/jobber/clear-tokens`) helps troubleshoot the connection.

### AI vendor recommendation (`laravel/ai`)

**`WorkOrderRecommendationService`** classifies a work order's issue and recommends a vendor (owner-preferred → building history → cross-site history → category → fallback). **Degrades gracefully**: if the AI provider isn't configured, it uses a deterministic keyword/heuristic engine. AI agents live in `app/Ai/Agents/`. Provider auto-selects Azure (if `AZURE_OPENAI_*` set) else OpenAI.

### Asana (limited)

A single console command `asana:set-dues` (`UpdateTaskDueDate`) sets due dates on subtasks in specific "lease on the market" Asana projects. No Asana webhook/route exists.

### AWS S3 & Reverb

- **S3** is fully configured but the **default disk is `local`**; Twilio media goes to `local`, Jobber images to `public`. Set `FILESYSTEM_DISK=s3` to switch.
- **Reverb** provides WebSocket broadcasting. Channels are declared/authorized; the frontend subscribes via Laravel Echo for live updates. (Set `BROADCAST_CONNECTION=reverb` and run `reverb:start`.)

---

## 9. Background Jobs, Commands & Scheduling

### Queued jobs (`app/Jobs/`) — require a running queue worker

| Job | Purpose |
|---|---|
| `ImportWorkOrderJob` | Bulk-imports work orders (chunks of 100) from PropertyWare; creates tenant/owner/user records; chains `GenerateWorkOrderRecommendationJob` for new orders. |
| `GenerateWorkOrderRecommendationJob` | Runs `WorkOrderRecommendationService->generate()`. |
| `SyncWorkOrderDetails` | Maps a PropertyWare payload onto an existing work order. |
| `UpdateWorkOrder` | Pushes local changes **back to PropertyWare** (transactional). |
| `UploadAttachment` | Optimizes an image then uploads to PropertyWare. |
| `SendConversationMessageJob` | Sends outbound SMS via Twilio, tracks status on `Conversation`. |
| `SendJobberTextMessageJob` | Sends Jobber SMS (with media); backoff 60/180/300s. |
| `GenerateOnboardingPdfJob` / `GenerateW9PdfJob` | Generate onboarding / W-9 PDFs from captured signatures. |

### Console commands (`app/Console/Commands/`)

`import:work-orders`, `import:all-work-orders`, `update:work-orders-status`, `import:all-vendors`, `import:buildings-from-work-orders`, `sync:building-details`, `sync:work-order-closing-comments`, `jobber:import-jobs`, `jobber:refresh-token`, `twilio:sync-phone-numbers`, `twilio:import-inbound-messages`, `jobs:send-reminders`, `asana:set-dues`.

### Scheduler (`routes/console.php`) — requires system cron running `schedule:run`

| Schedule | Command | Notes |
|---|---|---|
| Every 10 min | `import:work-orders` | Core PropertyWare sync; chains `update:work-orders-status` on success. |
| Every 5 min | `twilio:import-inbound-messages` | Safety-net backfill for missed inbound SMS. |
| Every 30 min | `jobber:refresh-token` | Keeps Jobber OAuth alive. |
| Daily | `import:buildings-from-work-orders` | Fills missing building records. |
| Daily 10:00 CT | `jobs:send-reminders` | Tenant SMS/email reminders 14, 7 and 3 days before a TBP visit. |
| Daily 08:00 CT | `jobs:send-reminders --days=1` | Day-before "visit tomorrow" reminder for TBP visits. |
| Daily 01:30 CT | `twilio:sync-phone-numbers` | Nightly Twilio number sync. |

Manual/triggered: `import:all-work-orders`, `import:all-vendors`, `sync:building-details`, `sync:work-order-closing-comments`, `jobber:import-jobs`, `asana:set-dues`.

---

## 10. Frontend Architecture

A **single-page app built with Inertia.js v2 + Vue 3**. Controllers return `Inertia::render('PageName', [...props])`; Inertia mounts the matching Vue component from `resources/js/Pages/`. Bundled with Vite, styled with Tailwind.

- **`app.js`** — bootstraps Inertia, registers **Ziggy** (`route()` in JS), and globally pre-registers the UI component library + lucide icons.
- **`echo.js`** — configures Laravel Echo over the Reverb broadcaster (params from `VITE_REVERB_*`).
- **Layouts** — `AppLayout.vue` (authenticated shell: sidebar nav, header, user menu, guides) and `AuthLayout.vue` (login/register).
- **`Components/ui/`** — a shadcn-vue-style design system (~50 primitives) on Reka UI / Radix Vue: dialogs, forms (vee-validate + zod), tables, charts (Unovis & Chart.js), sidebar, toast, etc.
- **Pages** map 1:1 to controllers (WorkOrder, Building, Calendar, Conversation, Inspection, Invoice, Owner, Tenant, Vendor, User, Reports, ServiceStatus, Task, TaskTemplate, VendorPortal, etc.); each has a `Partials/` folder for page-specific child components. The main work-order detail UI is `resources/js/Pages/WorkOrder/Partials/WorkOrderDetails.vue` (details, tasks, notes, vendor edit, role-based conversation tabs, service schedule, invoices, attachments).
- **Forms** use `vee-validate` + `@vee-validate/zod` with Inertia's `useForm`; specialized inputs include date pickers, multiselect, image cropper, and signature capture (`vue3-signature`).
- **Calendar** uses `@schedule-x`; **charts** use Chart.js / Unovis.
- **Realtime** — pages subscribe via the `useEchoPublic` composable (e.g. `Inspection/Schedules.vue` listens for `VisitUpdated`/`VisitDeleted`; work-order lists refresh live when the importer ingests new data). Toasts surface flash messages.

---

## 11. Operational Runbook

For the system to function fully in any environment, **all three background processes** must be running in addition to the web server:

1. **Queue worker** (`queue:work` / `queue:listen`) — SMS sending, PropertyWare write-backs, attachment uploads, PDF generation, AI recommendations.
2. **Scheduler** — system cron running `php artisan schedule:run` every minute drives the import/sync loops.
3. **Reverb server** (`reverb:start`) — realtime UI updates.

### Common troubleshooting

| Symptom | Likely cause |
|---|---|
| "Missing" work orders / records in tinker or for a user | A global scope is filtering by role — query as admin or temporarily bypass the scope. |
| Outbound SMS stuck / not sent | Queue worker not running, or Twilio creds invalid; check `conversations.twilio_status` and `activity_log`. |
| Inbound SMS not appearing | Webhook misconfigured in Twilio console; the 5-min `twilio:import-inbound-messages` backfill should recover them. |
| Work orders not importing | PropertyWare creds/headers, or the 10-min `import:work-orders` schedule not running. |
| Jobber data not syncing | OAuth token expired — visit `/jobber/diagnose`, then `/jobber/reconnect`. |
| Frontend change not visible | Assets not rebuilt — run `npm run dev` / `npm run build`. |
| Vite manifest exception | Same as above — build assets. |

### Webhooks to register with providers

- Twilio inbound SMS → `POST {APP_URL}/api/twilio/webhook`
- Twilio status callback → `POST {APP_URL}/api/twilio/status-callback`
- Jobber → `POST {APP_URL}/api/jobber/webhook` (signed with `JOBBER_SECRET`)
- Jobber OAuth redirect → `{APP_URL}/jobber/callback` (must equal `JOBBER_CALLBACK_URL`)

---

*Generated from a source-code scan. Verify specifics against the code when in doubt — file references are given throughout (`app/Services/`, `app/Models/`, `app/Http/Controllers/`, `routes/`, `config/`).*
