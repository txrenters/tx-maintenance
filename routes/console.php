<?php

use Illuminate\Support\Facades\Schedule;

// Work order sync runs as three independent schedules so the fast lane
// (Schedule 1) is never blocked by the bulk REST sync or the per-work-order
// document API calls. Overlap locks expire quickly so a crashed run cannot
// stall the schedule for hours (the default lock lasts 24h).

// Schedule 1 — fast lane: newest work orders + SOAP-only nested data
// (tenant/owner/lease/notes). Lightweight, so new/emergency work orders
// reach the dashboard within minutes.
//
// Kept at every 10 minutes (the frequency this has run at safely for months)
// so the deploy adds no extra SOAP call volume against PropertyWare's
// undocumented rate limit and Akamai edge protection. It can be tightened to
// every 5 minutes later once PropertyWare support confirms the limit is safe.
Schedule::command('import:work-orders')
    ->everyTenMinutes()
    ->withoutOverlapping(15)
    ->runInBackground();

// Schedule 2 — bulk sync: status/category/vendors for the wider (~5000) set
// via REST. Independent of the fast lane.
Schedule::command('update:work-orders-status')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->runInBackground();

// Schedule 3 — documents: one PropertyWare call per work order, the slowest
// part of the old combined run, now isolated on its own schedule.
Schedule::command('import:work-order-documents')
    ->everyTenMinutes()
    ->withoutOverlapping(30)
    ->runInBackground();

Schedule::command('import:buildings-from-work-orders')
    ->daily();

// Fill in each building's real street address from PropertyWare. Runs after the
// buildings import above so newly-created buildings get their address the same
// night; --force is omitted so it only fetches buildings still missing details.
// Owner notifications use this address when a work order has no tenant address.
Schedule::command('sync:building-details')
    ->dailyAt('00:30')
    ->withoutOverlapping(30)
    ->runInBackground();

// Refresh Jobber token every 30 minutes to prevent expiration. Never overlap:
// Jobber refresh tokens are single-use, so two concurrent refreshes kill the
// stored token permanently (the service also serializes behind a cache lock).
Schedule::command('jobber:refresh-token')
    ->everyThirtyMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

// With no --days the command runs its defaults: the 3, 7, and 14-day tiers.
Schedule::command('jobs:send-reminders')
    ->timezone('America/Chicago')
    ->dailyAt('10:00')
    ->withoutOverlapping()
    ->runInBackground();

// Last reminder for TBP visits happening tomorrow. Runs earlier than the
// 10:00 tiers on purpose; --days=1 leaves the 3/7/14-day defaults untouched.
Schedule::command('jobs:send-reminders --days=1')
    ->timezone('America/Chicago')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('vendors:followup-unscheduled')
    ->timezone('America/Chicago')
    ->dailyAt('10:05')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('twilio:sync-phone-numbers')
    ->timezone('America/Chicago')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('twilio:import-inbound-messages')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Tenant-easy-fix photo links: initial sends + capped reminders. Gated off by
// default (TENANT_PORTAL_SMS_ENABLED), so this is a no-op until enabled.
Schedule::command('tenant-portal:send-links')
    ->everyThirtyMinutes()
    ->withoutOverlapping(15)
    ->runInBackground();

// HOA violations: daily tenant reminders until the 5-business-day deadline,
// overdue escalation flag, and the corrected-confirmation email. Gated off by
// default (HOA_VIOLATION_SMS_ENABLED), so this is a no-op until enabled.
Schedule::command('hoa:send-reminders')
    ->timezone('America/Chicago')
    ->dailyAt('10:10')
    ->withoutOverlapping()
    ->runInBackground();

// Tenant vendor-contact follow-up: daily text to the tenant, starting the day
// after a vendor is assigned, asking whether the vendor has reached out, until a
// service schedule is set (or the cap is hit). Gated off by default
// (TENANT_VENDOR_FOLLOWUP_SMS_ENABLED), so this is a no-op until enabled.
Schedule::command('tenants:followup-vendor-contact')
    ->timezone('America/Chicago')
    ->dailyAt('10:15')
    ->withoutOverlapping()
    ->runInBackground();

// Owner appointment follow-up: daily text to each owner, starting the day after
// a service schedule is set, asking whether they want to join the technician
// call or approve the work order, until they reply (or the cap is hit / the
// appointment arrives). Gated off by default
// (OWNER_SCHEDULE_FOLLOWUP_SMS_ENABLED), so this is a no-op until enabled.
Schedule::command('owners:followup-schedule')
    ->timezone('America/Chicago')
    ->dailyAt('10:20')
    ->withoutOverlapping()
    ->runInBackground();

// Tenant appointment reminder: daily text to the tenant, starting the day after
// a service schedule is set, keeping the date in front of them until the
// appointment arrives (or they reply / the cap is hit). Gated by
// TENANT_SCHEDULE_FOLLOWUP_SMS_ENABLED.
Schedule::command('tenants:followup-schedule')
    ->timezone('America/Chicago')
    ->dailyAt('10:25')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('emails:sync-replies')
    ->everyThreeMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('tenant-emails:sync-replies')
    ->everyThreeMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('owner-emails:sync-replies')
    ->everyThreeMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Self-healing backstop for the photo/attachment mirror to PropertyWare: any
// upload whose confirmation never landed (pw_file_name still null) is checked
// against PropertyWare and re-dispatched, so a silently lost photo cannot stay
// lost past a day. Steady state is zero candidates, which means zero
// PropertyWare API calls.
Schedule::command('attachments:repair-pw-uploads')
    ->timezone('America/Chicago')
    ->dailyAt('02:00')
    ->withoutOverlapping(30)
    ->runInBackground();

// The same backstop for HOA violation notices, which take a separate upload
// job and so are skipped by the sweep above. Fifteen minutes later so the two
// don't hit the PropertyWare document API at the same time. Steady state is
// zero candidates missing from PropertyWare, which means one listing call per
// work order that still has an unconfirmed notice and nothing else.
Schedule::command('hoa:repair-notice-uploads')
    ->timezone('America/Chicago')
    ->dailyAt('02:15')
    ->withoutOverlapping(30)
    ->runInBackground();

// Courtesy closers: ask the AI which newest inbound messages are just "thank
// you" / "ok great" so the awaiting-reply badge, Inbox and unanswered report
// stop holding those threads open. Fails open - an unjudged message or an AI
// outage means the thread simply keeps counting as awaiting. Internal-only;
// COURTESY_CLOSER_FILTER_ENABLED=false switches the filtering off without
// losing stored verdicts.
Schedule::command('inbox:classify-courtesy')
    ->everyTenMinutes()
    ->withoutOverlapping(15)
    ->runInBackground();

// Message triage: tag each thread's newest unanswered inbound message with
// what it is (reschedule request, complaint, access issue, job done,
// question) and record concretely proposed appointment times as suggestions
// staff accept or dismiss. Read-only — it labels threads, it never sends
// anything. Fails open: an unjudged message simply shows no chip.
// INTENT_TRIAGE_ENABLED=false switches it off without losing stored labels.
Schedule::command('inbox:triage-messages')
    ->everyTenMinutes()
    ->withoutOverlapping(15)
    ->runInBackground();

// Auto-tick checklist tasks the database already proves done (category/zone/
// plan filled, vendor assigned, schedule set, photos or invoice uploaded,
// photos synced/published), so coordinators stop re-confirming facts the
// system already knows. Never advances the service status — it only touches
// "Not Changed" templates and completes with a cascade-free update.
Schedule::command('tasks:auto-complete')
    ->everyThirtyMinutes()
    ->withoutOverlapping(20)
    ->runInBackground();

// The document import above steadily accumulates duplicate-named PropertyWare
// system files, round-tripped copies of our own uploads on old rows, and
// THMP_ thumbnails; this collapses them. The import's skip-guards mean deleted
// rows are not re-imported, so the cleanup converges instead of churning.
// 02:30 stays off the :00/:10 import ticks and clear of the 02:00/02:15
// repair backstops.
Schedule::command('work-order-documents:dedupe')
    ->timezone('America/Chicago')
    ->dailyAt('02:30')
    ->withoutOverlapping(60)
    ->runInBackground();

// Closing comments keep changing in PropertyWare after a work order was
// imported; this fills local blanks nightly. No --overwrite on purpose: a
// non-empty local comment (staff-written) always wins.
Schedule::command('sync:work-order-closing-comments')
    ->timezone('America/Chicago')
    ->dailyAt('03:00')
    ->withoutOverlapping(60)
    ->runInBackground();

// Detector for the PropertyWare bug where HOA violation work orders arrive
// tenant-less — which silently stops every tenant text for that violation.
// Dry-run on purpose: linking a tenant resumes their SMS at the next
// hoa:send-reminders run, so the actual fix stays a human decision. This only
// raises a one-time bell per affected work order.
Schedule::command('hoa:relink-tenants --dry-run --notify')
    ->timezone('America/Chicago')
    ->dailyAt('03:15')
    ->withoutOverlapping(30)
    ->runInBackground();

// Read-only owner-data audit: duplicate owners, shared emails, duplicate
// work-order links, and owners with work orders but no phone on file (their
// texts no-op silently). Raises one summary bell, and only when the counts
// change, so standing problems don't ring nightly.
Schedule::command('owners:audit --notify')
    ->timezone('America/Chicago')
    ->dailyAt('03:30')
    ->withoutOverlapping(30)
    ->runInBackground();

// Weekly vendor roster refresh from PropertyWare — new vendors appear and
// contact details update without anyone importing them by hand. Profile
// fields only: portal passwords are set once on create, never overwritten.
// Sunday 04:00, well clear of the nightly jobs above.
Schedule::command('import:all-vendors')
    ->timezone('America/Chicago')
    ->weeklyOn(0, '04:00')
    ->withoutOverlapping(120)
    ->runInBackground();
