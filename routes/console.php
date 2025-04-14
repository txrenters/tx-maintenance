<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('import:work-orders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('asana:set-dues')->everyThreeMinutes()->withoutOverlapping()->between('0:01', '23:59');
