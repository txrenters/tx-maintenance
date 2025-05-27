<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('import:work-orders')
    ->everyFiveMinutes() 
    ->timezone('America/Chicago')
    ->weekdays()
    ->withoutOverlapping()
    ->runInBackground();
// Schedule::command('asana:set-dues')->everyTwoMinutes()->withoutOverlapping()->between('0:01', '23:59')->runInBackground();
