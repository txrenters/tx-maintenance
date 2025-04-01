<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule::command('import:vendors')->dailyAt('00:00')->withoutOverlapping();
// Schedule::command('import:owners')->dailyAt('00:30')->withoutOverlapping();
// Schedule::command('import:work-orders')->everyFiveMinutes()->withoutOverlapping();
