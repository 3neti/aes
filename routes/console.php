<?php

use App\Jobs\RecordRuntimeHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('election:runtime-heartbeat scheduler')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::job(
    (new RecordRuntimeHeartbeat('queue'))->onQueue('default'),
)->everyMinute()->withoutOverlapping();
