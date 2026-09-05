<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The ONLY cPanel cron entry needed is: * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
// Everything below is then driven by Laravel's own in-process scheduler — no separate cron lines
// per task, and no long-running Node/PHP process required.
Schedule::command('ical:sync')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('holds:purge')->everyMinute();
