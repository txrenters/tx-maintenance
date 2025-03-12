<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('import:vendors')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('import:owners')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('import:work-orders')->everyFiveMinutes()->withoutOverlapping();
