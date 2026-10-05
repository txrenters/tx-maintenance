# Reverb realtime updates — design

Date: 2026-09-25
Status: approved in conversation, awaiting written-spec review

## Goal

Maintenance staff work their chats in this app. New messages, delivery
status, notifications and board changes should appear without a reload, on
every screen that shows them, for every party — including owner/tenant
messages that now arrive from the support app (tx-chatbot) instead of Twilio.

Polling stays as a safety net: fast when Reverb is unreachable (Azure today,
where `startup.sh` never starts Reverb), slow when the socket is connected.

## Decisions (from the user)

- Scope: staff screens **and** the no-login tenant, owner and vendor portals.
- Also realtime: notification bell, message delivery status, work order
  boards / Dashboard / Calendar / Inspections.
- Keep polling as a slowed-down fallback, not removed.
- Owner/tenant messaging goes through the support app (ChatbotHub →
  `/api/chatbot/events`); vendors stay on Twilio. Staff may reply from either
  app, but maintenance staff mostly use this one.
- Azure deployment configuration is not changed.

## Approach: "something changed" signals

The server broadcasts small signals — ids only, never message text or phone
numbers — on private channels. The page refetches through the endpoints and
Inertia props it already uses, so existing authorization (`WorkOrderScope`,
`ConversationScope`, `scopeForVendorThread`, portal token middleware) decides
what each viewer sees. Rejected: broadcasting full payloads, or Laravel model
broadcasting, both of which would have to re-implement those visibility rules
as channel rules and risk leaking one party's messages to another.

## Server side

### Signal source

A `RealtimeSignals` service is the only code that dispatches broadcasts. It is
called from model hooks, so every write path is covered without touching its
callers:

| Hook | Signal | Channels |
|---|---|---|
| `Conversation` created | `thread.changed` | `work-order.{id}`, `inbox`, matching portal channel |
| `Conversation` updated where `message`, read flags (`is_read`, `read_by_owner`, `read_by_tenant`), `twilio_status` or `twilio_error_message` changed | `thread.changed` | same |
| `Conversation` updated where `work_order_id` changed (`message.moved`) | `thread.changed` | both the original and the new work order's channels |
| `Conversation` deleted | `thread.changed` | same |
| `WorkOrder` saved / deleted | `board.changed` | `boards`, `work-order.{id}` |
| `JobberVisit` saved / deleted | `VisitUpdated` / `VisitDeleted` | `boards` |

Payloads:

- `thread.changed`: `{ work_order_id, party, conversation_id }` where `party` is
  `owner`, `tenant` or `vendor`, plus `vendor_id` when the thread is a vendor
  thread.
- `board.changed`: `{ work_order_id }`.
- `VisitUpdated` / `VisitDeleted`: `{ visit_id }` (the event names the
  Schedules page already listens for).

Covered write paths include the Twilio inbound processor and status callback,
`SendConversationMessageJob`, `ChatbotEventProcessor::process()` and
`refreshStatus()`, `ChatbotHub::send()`, the portal message endpoints, and the
automated senders/follow-up commands.

### Delivery rules

- Sent with `ShouldBroadcastNow`, dispatched after the surrounding database
  transaction commits (`afterCommit`). No queue dependency, so signals work on
  the VPS before the `workers` profile is enabled.
- Deduplicated per request: repeated signals for the same channel + key within
  one request/job (e.g. the processor's `save()` followed by
  `refreshStatus()`) collapse into one broadcast, sent at the end of the
  request/job.
- Every broadcast is wrapped in try/catch. A broadcaster failure never fails
  the save, the job, or the webhook response. Failures are logged at most once
  per minute (cache-throttled), not per message.

### Channels (all private)

| Channel | Authorization |
|---|---|
| `work-order.{id}` | Authenticated staff user who can load that work order under `WorkOrderScope` |
| `inbox` | Authenticated staff user with access to the Inbox (same gate as the Inbox route) |
| `boards` | Authenticated staff user. Replaces the open `workOrders`, `jobs` and public `visits` channels |
| `App.Models.User.{id}` | Unchanged; that user only |
| `portal.tenant.{tokenId}` | Browser presenting a valid `TenantUploadToken` for that id |
| `portal.owner.{tokenId}` | Browser presenting a valid `OwnerPortalToken` for that id |
| `portal.vendor.{assignmentId}` | Browser presenting a valid `WorkOrderVendor.access_token` for that assignment; receives only its own vendor's thread |

Portal authorization: each portal route group gets a
`POST /{tenant|owner|vendor}-portal/{token}/broadcasting/auth` route behind
the existing `tenant.portal` / `owner.portal` / `vendor.portal` middleware. It
signs a subscription only for the portal channel matching the token the
middleware resolved; any other channel name is refused (403). A bad or
revoked token 404s, as the portal page does. The Jobber portal has no message
thread and gets no channel.

Portal signals are routed by the conversation's work order and party: a
tenant thread change signals every tenant portal token for that work order;
an owner thread change signals the owner portal token(s) for that work order
(matching `owner_id` when set); a vendor thread change signals only the
assignment whose `vendor_id` matches.

## Frontend

### Echo configuration

`bootstrap.js` currently builds Echo twice (`new Echo` and `configureEcho`)
and `echo.js` a third time. Collapse to one configuration used by both
`window.Echo` and `@laravel/echo-vue`. Portal pages set `authEndpoint` to
their token route.

### `useRealtime` composable

The only code that touches Echo for these features.

```js
useRealtime({
  channel: `work-order.${id}`,
  on: { "thread.changed": refresh },
  poll: { run: refresh, fallbackMs: 5000, connectedMs: 60000 },
});
```

- Subscribes on mount, leaves on unmount.
- Tracks connection state. While connected and subscribed, runs `poll.run`
  every `connectedMs`. If the socket is down, never connects, or the channel
  auth is refused, runs it every `fallbackMs` (today's speed).
- On reconnect, runs `poll.run` once immediately to catch missed signals.
- Coalesces bursts: signals within ~300ms trigger one refresh.
- Never surfaces Reverb errors to the user.

### Screens

| Screen | Channel | On signal | Poll: fallback → connected |
|---|---|---|---|
| Work order page chat panels (`WorkOrder/Show.vue`, `VendorTenantConversation`, `VendorOwnerConversation`) | `work-order.{id}` | Partial reload of the conversation props | none today → 5s fallback, 60s connected |
| Inbox (`Inbox/Index.vue`) | `inbox` | Reload thread list and counts; refresh the open thread if it matches | none today → 15s fallback, 60s connected |
| Message alerts (`useMessageAlerts`) | `inbox` | Run existing `poll()` now (server already returns only unseen messages) | 5s → 60s |
| Notification bell (`AppLayout`) | `App.Models.User.{id}` (already joined for toasts) | `fetchNotifications()` | 5s → 60s |
| Delivery status | rides on `thread.changed` | Same reload as the chat panel | — |
| Work order boards (`Index`, `Inspections`, `LawnCare`, `Close`), `Dashboard`, `Calendar/Index`, `Inspection/Index`, `Inspection/Schedules` | `boards` | Partial reload of the page's existing `only:` props | existing interval → 120s |
| Tenant / owner / vendor portals | `portal.{party}.{id}` via portal auth route | Partial reload of the thread | none today → 15s fallback, 60s connected |

## Error handling

- Reverb down: saves, jobs and webhooks unaffected; pages poll at fallback speed.
- Portal token revoked: auth route 404s; page behaves as today.
- Channel auth refused (e.g. staff without access to that work order): the
  composable stays on fallback polling; no user-facing error.
- Moved message: both work orders are signalled, so it leaves one panel and
  appears in the other.

## Testing

PHPUnit feature tests with broadcast/event fakes:

- `thread.changed` is broadcast to `work-order.{id}`, `inbox` and the right
  portal channel for: Twilio inbound, `ChatbotEventProcessor`
  `message.received`, `message.sent` from the support app, a portal post.
- The payload contains no message body or phone numbers.
- `refreshStatus()` and the Twilio status callback broadcast once, and not at
  all when status is unchanged.
- `message.moved` signals both work orders.
- Channel authorization: staff allowed/denied per `WorkOrderScope`; a portal
  token authorizes only its own channel; revoked token 404s; vendor A's portal
  cannot join vendor B's channel.
- A throwing broadcaster does not fail the model save; the chatbot webhook
  still returns 204.
- `WorkOrder` and `JobberVisit` saves broadcast on `boards`.

The repo has no JS test runner, so the frontend is verified by running the app
with Reverb and checking each screen in two browser tabs, plus with Reverb
stopped to confirm the fallback polling.

## Rollout

- Azure: unchanged. Reverb is not started there, so pages run at fallback
  poll speeds — current behaviour.
- VPS (`~/projects/tx-maintenance-vps`): Reverb already runs in the stack.
  `env.docker.example` gains notes for `CHATBOT_HUB_*` alongside the existing
  Reverb keys.
- Builds on the uncommitted chatbot-hub work. Implemented directly in the
  working tree and not committed; shipped to the VPS with
  `tx-maintenance-vps/bin/push.sh`, which deploys the working tree as-is.

## Out of scope

- Syncing read/unread state between the support app and this app.
- Presence ("who is viewing this work order") and typing indicators.
- Adding Reverb to the Azure deployment.
