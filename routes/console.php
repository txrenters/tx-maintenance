<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('import:work-orders')
    ->everyTenMinutes()
    ->timezone('America/Chicago')
    ->weekdays()
    ->withoutOverlapping()
    ->runInBackground();

// Refresh Jobber token every 30 minutes to prevent expiration
Schedule::command('jobber:refresh-token')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('jobs:send-reminders')
    ->timezone('America/Chicago')
    ->dailyAt('16:00')
    ->runInBackground();