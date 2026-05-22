<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('import:work-orders')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->then(function () {
        Artisan::call('update:work-orders-status');
    });

Schedule::command('import:buildings-from-work-orders')
    ->daily();

// Refresh Jobber token every 30 minutes to prevent expiration
Schedule::command('jobber:refresh-token')
    ->everyThirtyMinutes()
    ->runInBackground();

Schedule::command('jobs:send-reminders')
    ->timezone('America/Chicago')
    ->dailyAt('16:00')
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
