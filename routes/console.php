<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('import:work-orders')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('asana:set-dues')
    ->everyTwoMinutes()
    ->appendOutputTo(storage_path('logs/asana-set-due-dates.log'))
    ->withoutOverlapping();