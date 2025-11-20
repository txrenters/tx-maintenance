<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('import:work-orders')
    ->everyFiveMinutes()
    ->weekdays()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('update:work-orders-status')
    ->everyTenMinutes()
    ->weekdays()
    ->withoutOverlapping()
    ->runInBackground();

// Refresh Jobber token every 30 minutes to prevent expiration
Schedule::command('jobber:refresh-token')
    ->everyThirtyMinutes()
    ->weekdays()
    ->runInBackground();

Schedule::command('jobs:send-reminders')
    ->timezone('America/Chicago')
    ->dailyAt('16:00')
    ->runInBackground();
