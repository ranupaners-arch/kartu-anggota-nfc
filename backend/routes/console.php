<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Illuminate\Support\Facades\Schedule::command('events:close')->everyMinute();
// Detak untuk health check: scheduler hidup, dan queue worker memproses job.
Illuminate\Support\Facades\Schedule::call(fn () => Illuminate\Support\Facades\Cache::put(App\Services\HealthService::SCHEDULER_BEAT, now()->toIso8601String(), now()->addDay()))
    ->everyMinute()->name('health:scheduler-beat');
Illuminate\Support\Facades\Schedule::job(new App\Jobs\QueueHeartbeat)->everyMinute();
Illuminate\Support\Facades\Schedule::command('app:retention')->dailyAt('02:30')->withoutOverlapping();
