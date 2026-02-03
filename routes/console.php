<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('import:work-orders')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->then(function () {
        Artisan::call('update:work-orders-status');
    });

// Refresh Jobber token every 30 minutes to prevent expiration
Schedule::command('jobber:refresh-token')
    ->everyThirtyMinutes()
    ->runInBackground();

Schedule::command('jobs:send-reminders')
    ->timezone('America/Chicago')
    ->dailyAt('16:00')
    ->withoutOverlapping()
    ->runInBackground();
