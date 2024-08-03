<?php

use Illuminate\Support\Facades\Schedule;
Schedule::command('app:freeze-job')->everyFiveMinutes();
Schedule::command('shipping:update')->everyThirtySeconds();

// use Illuminate\Foundation\Inspiring;
// use Illuminate\Support\Facades\Artisan;
// use Illuminate\Console\Command;
// use Illuminate\Support\Facades\Http;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote')->hourly();

