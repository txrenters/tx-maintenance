# Vendor Email System — Design

**Date:** 2026-07-17
**Status:** Approved (pending spec review)
**Scope:** Vendor emails only. Owner/tenant channels are explicitly out of scope but the data model leaves room for them.

## Problem

When a vendor is assigned a work order we already email them (via Office365 SMTP) and text them. The email is fire-and-forget: it is not persisted, there is no history, staff cannot compose a follow-up email from the app, and vendor replies vanish into the mailbox.

We want to:

1. Persist every email we send to a vendor, tied to the work order **and** the vendor.
2. Show the email history (sent + received) on the work order page, scoped to the vendor.
3. Let staff manually compose and send an email to the vendor from the app.
4. Capture vendor replies **to emails we sent** and attach them to the same work order + vendor thread.

## Decisions (locked)

- **Data model:** a dedicated `EmailMessage` model (not an extension of the SMS `Conversation` model).
- **Sending:** Microsoft Graph **draft-then-send** (create draft → send) from the `workorders@texasrenters.com` mailbox (replaces the SMTP path for these emails). Sent mail lands in the mailbox Sent folder and we capture the authoritative `internetMessageId` + `conversationId` on every outbound email.
- **Inbound:** a scheduled Artisan command polls the `workorders@` inbox via Graph and matches replies.
- **Reply matching:** primary key is a subject correlation tag `[TX-<workOrderNo>-<vendorId>]` embedded in every outbound subject; the poll parses it from the inbound subject. Fallback: match the reply's `In-Reply-To`/`References`/`conversationId` against the captured outbound ids.
- **Permissions:** `Mail.ReadWrite` + `Mail.Send` (application permissions).
- **Inbound attachments:** **not** downloaded. The inbound row records `has_attachments = true` and the thread shows a "has attachments — view in your email" note. Only outbound attachments (what we send) are stored.
- **UI:** a new "Emails" tab on the work order detail page (`Show.vue`), scoped to the assigned vendor.
- **Send origin:** staff/coordinator side only. Vendor-portal sending is out of scope for now.

## Credentials / config

Already present in `.env`:

```
MICROSOFT_TENANT_ID=...
MICROSOFT_CLIENT_ID=...
MICROSOFT_CLIENT_SECRET=...
MICROSOFT_MAILBOX=workorders@texasrenters.com
```

Add a `microsoft` block to `config/services.php`:

```php
'microsoft' => [
    'tenant_id' => env('MICROSOFT_TENANT_ID'),
    'client_id' => env('MICROSOFT_CLIENT_ID'),
    'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
    'mailbox' => env('MICROSOFT_MAILBOX', 'workorders@texasrenters.com'),
],
```

No new Composer dependencies — Graph is called via the existing `Http`/Guzzle
client. Two new **npm** dependencies (approved): `@tiptap/vue-3` and
`@tiptap/starter-kit` for the rich-text compose editor.

## Data model

### `email_messages`

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `work_order_id` | fk, indexed | |
| `vendor_id` | fk, nullable, indexed | always set for now; nullable for future owner/tenant |
| `direction` | string | `outbound` \| `inbound` |
| `subject` | string | includes the correlation tag on outbound |
| `body_html` | longtext, nullable | |
| `body_text` | longtext, nullable | |
| `from_email` | string | |
| `to_email` | string | primary recipient |
| `cc` | json, nullable | |
| `correlation_tag` | string, nullable, indexed | e.g. `TX-12345-45`; set on outbound, parsed on inbound |
| `graph_message_id` | string, nullable, indexed | Graph message id — captured on outbound (draft-then-send), and used for inbound dedupe |
| `graph_conversation_id` | string, nullable, indexed | captured on outbound; fallback match key for inbound |
| `internet_message_id` | string, nullable, indexed | outbound: our sent Message-ID (fallback match target); inbound: the reply's own Message-ID |
| `in_reply_to` | string, nullable, indexed | inbound: the Message-ID the reply is answering (fallback match) |
| `has_attachments` | boolean, default false | inbound: reply carried attachments (flag only — files not downloaded) |
| `sent_by_user_id` | fk users, nullable | staff who composed a manual email; null = automated/inbound |
| `emailed_at` | timestamp | sent time (outbound) / received time (inbound) |
| `timestamps` | | |

Index notes: `(work_order_id, vendor_id)` composite for the thread query; unique index on `graph_message_id` (nullable) to hard-stop duplicate inbound inserts.

### `email_attachments` (outbound files only)

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `email_message_id` | fk, indexed | |
| `filename` | string | |
| `mime` | string | |
| `size` | integer | bytes |
| `path` | string | local disk path |
| `timestamps` | | |

Used for **outbound only**: manual uploads + the automated WO-Information PDF,
stored as full bytes on local disk so staff can re-download what we sent. Inbound
reply attachments are **not** stored — the inbound row's `has_attachments` flag
drives a "view in your email" note instead.

### Models & relations

- `EmailMessage belongsTo WorkOrder`, `belongsTo Vendor`, `belongsTo User (sentByUser)`, `hasMany EmailAttachment`.
- `EmailAttachment belongsTo EmailMessage`.
- `WorkOrder hasMany emailMessages`.
- `EmailMessage` casts: `cc` array, `has_attachments` bool, `emailed_at` datetime.

## Microsoft Graph service — `App\Services\MicrosoftGraphMailService`

Thin wrapper over the Graph REST API using `Http`.

- **Token:** client-credentials grant from
  `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`, scope
  `https://graph.microsoft.com/.default`. Cached in the cache store until shortly
  before `expires_in`.
- **`sendMail(string $to, array $cc, string $subject, string $html, array $attachments = []): array`**
  Draft-then-send so we capture ids:
  1. `POST /users/{mailbox}/messages` (create draft) with `toRecipients`,
     `ccRecipients`, `subject`, `body` (contentType HTML), and `attachments` as
     `#microsoft.graph.fileAttachment` (base64 `contentBytes`). Response yields
     `id`, `internetMessageId`, `conversationId`.
  2. `POST /users/{mailbox}/messages/{id}/send`.
  Returns `['graph_message_id' => id, 'internet_message_id' => ..., 'graph_conversation_id' => ...]`.
- **`fetchInbox(CarbonInterface $since): array`**
  `GET /users/{mailbox}/mailFolders/inbox/messages` selecting
  `id,subject,from,receivedDateTime,hasAttachments,conversationId,internetMessageId,body`
  plus `internetMessageHeaders` (for `In-Reply-To`/`References`),
  `$filter=receivedDateTime ge {since}`, ordered ascending, paged.
- **`markRead(string $messageId): void`**
  `PATCH /users/{mailbox}/messages/{id}` `{ isRead: true }` (allowed by ReadWrite);
  a best-effort extra guard on top of the cursor + dedupe.

Errors are logged and surfaced so the caller (job/command) can retry. All HTTP is
faked in tests via `Http::fake`.

## Outbound path

Single entry point: `MicrosoftGraphMailService` is called through an application
service `App\Services\WorkOrderEmailSender`:

```
sendVendorEmail(WorkOrder $wo, Vendor $vendor, string $subject, string $html,
                array $files = [], ?User $sentBy = null): EmailMessage
```

Responsibilities:
1. Build the correlation tag `TX-{$wo->work_order_no}-{$vendor->id}` and append
   `[{tag}]` to the subject if not already present.
2. Call `graph->sendMail(...)`, capturing the returned `graph_message_id`,
   `internet_message_id`, `graph_conversation_id`.
3. Persist the outbound `EmailMessage` (`direction=outbound`, `vendor_id`,
   `correlation_tag`, the captured Graph ids, `from_email`=mailbox,
   `to_email`=vendor email, `sent_by_user_id`, `emailed_at=now()`).
4. Persist `EmailAttachment` rows for any files (stored on local disk).

### (a) Automated assignment email

The vendor-facing **design and content of the automated email are unchanged** —
we keep the existing `emails.vendor-service-request` Blade view and its subject.
Only the transport (Graph instead of SMTP), persistence, subject tag, and CC list
change. The Tiptap editor applies to manual compose only, not this email.

`App\Jobs\SendVendorWorkOrderInformation` currently does
`Mail::to($vendor->email)->send(new VendorServiceRequestMail(...))`. Replace that
block with a call to `WorkOrderEmailSender::sendVendorEmail(...)`:
- Render the existing `emails.vendor-service-request` Blade view to an HTML string
  for the body (reuse the view as-is; do not duplicate or restyle the copy).
- Pass the generated WO-Information PDF bytes as an attachment.
- `sentBy = null` (system).

The CC list currently on `VendorServiceRequestMail` (`workorders@`, `mc@`, `ofm@`)
moves into the send call's `cc`. Because the From/mailbox is now `workorders@`,
drop `workorders@` from CC to avoid self-CC — leaving `mc@texasrenters.com` and
`ofm@txhomemp.com` (2 CCs).

`VendorServiceRequestMail` (the Mailable) is retained only as the view/subject
renderer, or its Blade view is rendered directly — implementation plan decides.
Nothing else may keep sending it via SMTP.

### (b) Manual compose

Handled by the controller `store` action below.

## Inbound path — `emails:sync-replies` command

`App\Console\Commands\SyncEmailReplies`, scheduled every 3 minutes.

1. Read a `last_synced_at` cursor (cache key `emails.replies.cursor`), defaulting
   to e.g. 1 hour ago on first run.
2. `graph->fetchInbox($cursor)`.
3. For each message, in ascending `receivedDateTime` order:
   - Skip if an `email_messages` row already exists with that `graph_message_id`
     (dedupe).
   - Match to an outbound email, in order:
     1. **Primary:** parse the correlation tag with `/\[TX-(\d+)-(\d+)\]/` from the
        subject → `work_order_no` + `vendor_id`.
     2. **Fallback:** if no tag, match the reply's `In-Reply-To`/`References`
        against an outbound `internet_message_id`, or `conversationId` against an
        outbound `graph_conversation_id`, and derive the work order + vendor from
        that row.
     No match → **ignore** (not a reply to one of our emails).
   - Resolve `work_order_id` from `work_order_no` and confirm the `vendor_id`
     is assigned to that work order. If either fails → ignore (log at debug).
   - Insert an inbound `EmailMessage` (`direction=inbound`, `from_email` = sender,
     `to_email` = mailbox, `subject`, `body_html`/`body_text` from Graph `body`,
     `graph_message_id`, `graph_conversation_id`, `internet_message_id`,
     `in_reply_to`, `has_attachments` (from Graph `hasAttachments` — flag only,
     files not downloaded), `emailed_at` = `receivedDateTime`).
   - `graph->markRead(id)` (best-effort).
4. Advance the cursor to the max `receivedDateTime` processed.

Reprocessing is prevented primarily by the cursor + unique `graph_message_id`
dedupe; marking read is an additional guard.

## Controller, routes, UI

### `App\Http\Controllers\WorkOrderEmailController`

- `index(WorkOrder $workOrder)` — returns the vendor-scoped email thread as JSON
  (`{ vendor_emails: [...] }`), ordered by `emailed_at`, eager-loading attachments.
  Mirrors the existing `fetch*Conversation` axios pattern used by `Show.vue`.
- `store(Request $request, WorkOrder $workOrder)` — validates `vendor_id`,
  `subject`, `body` (required; HTML from the Tiptap editor, sanitized per the
  HTML-safety note below), `attachments[]` (optional files). Calls
  `WorkOrderEmailSender::sendVendorEmail(...)`
  with the authenticated user as `sentBy`. Returns the created message / redirects
  back like the SMS send.

### Routes (`routes/web.php`)

- `GET  work-orders/{workOrder}/emails` → `index`, name `work_order.email.index`
- `POST work-orders/{workOrder}/emails` → `store`, name `work_order.email.send`

### Frontend

- New partial `resources/js/Pages/WorkOrder/Partials/VendorEmail.vue`, modeled on
  `VendorConversation.vue`:
  - Renders the thread: each item shows direction (sent/received), subject, from,
    timestamp, body, outbound attachment download links, and — for inbound replies
    that carried attachments — a "has attachments — view in your email" note (no
    download).
  - Compose box: `subject` input + a **Tiptap rich-text editor** (bold, italic,
    underline, bullet/ordered lists, link) whose HTML output becomes `body_html`
    (a plain-text version is derived for `body_text`) + file input; posts via
    `router.post(route('work_order.email.send'), formData)` and emits an update
    event to refetch. Extract the editor into a reusable
    `resources/js/Components/RichTextEditor.vue` so future channels/notes can share it.
- `Show.vue`:
  - Add an "Emails" entry to the tab config (with tooltip), gated to the same
    roles that see the vendor conversation tab.
  - Add `vendorEmails` ref + `fetchVendorEmails()` calling
    `work_order.email.index`, wired into `switchTab`.

## Error handling

- Graph token/send failures: logged; the automated send happens inside the
  existing queued job, so it retries with the job. Manual send surfaces a
  validation/flash error to the user on failure.
- Sync command failures on a single message are caught per-message and logged so
  one bad message does not stall the batch; the cursor only advances past
  successfully processed messages.
- Duplicate protection: unique `graph_message_id` index + existence check.
- **HTML safety:** email bodies are HTML and get rendered in the thread, so they
  must not be dropped into `v-html` raw. Inbound bodies come from outside our
  control (XSS surface) and outbound Tiptap HTML is still user-authored — sanitize
  HTML to an allowlist (bold/italic/underline/lists/links/paragraphs/breaks) before
  storing/displaying, or render inbound bodies inside a sandboxed iframe. The
  implementation plan picks the mechanism; no unapproved dependency is added
  without asking.

## Testing

Unit (`tests/Unit`):
- `MicrosoftGraphMailService`: token is cached and reused; `sendMail`
  (draft-then-send) posts the draft then the send and returns the captured ids;
  `fetchInbox` builds the correct query — all via `Http::fake`.

Feature (`tests/Feature`):
- `SyncEmailReplies`: a reply whose subject carries `[TX-<wo>-<vendor>]` creates a
  matching inbound `EmailMessage`; the `In-Reply-To`/`conversationId` fallback
  matches a reply with a stripped subject tag; a message with no match is ignored;
  a message whose vendor is not assigned to the work order is ignored; a message
  with an already-stored `graph_message_id` is not duplicated; a reply with
  attachments sets `has_attachments = true` without storing any files; cursor advances.
- `WorkOrderEmailController@store`: persists an outbound `EmailMessage` with the
  captured Graph ids, calls the sender (Graph faked), and stores attachments.
- `WorkOrderEmailController@index`: returns only the requested vendor's thread and
  not another vendor's emails on a shared work order.
- Automated path: assigning a vendor persists an outbound `EmailMessage` with the
  correlation tag (Graph faked), replacing the SMTP send.

## Out of scope (future)

- Owner and tenant email channels (model supports it via nullable `vendor_id` +
  a future recipient-type discriminator).
- Vendor-portal-side email composing.
