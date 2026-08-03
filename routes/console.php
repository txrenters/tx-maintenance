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

// Refresh Jobber token every 30 minutes to prevent expiration
Schedule::command('jobber:refresh-token')
    ->everyThirtyMinutes()
    ->runInBackground();

Schedule::command('jobs:send-reminders')
    ->timezone('America/Chicago')
    ->dailyAt('10:00')
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
