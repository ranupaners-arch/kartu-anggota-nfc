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
// Ringkasan mingguan untuk pengurus: Senin 07.00 WIB (hanya bila email server sudah diatur).
Illuminate\Support\Facades\Schedule::command('ringkasan:mingguan')->weeklyOn(1, '07:00')->timezone('Asia/Jakarta')->withoutOverlapping();
// Pengingat push portal anggota ±1 jam sebelum kegiatan (nonaktif bila kunci VAPID kosong).
Illuminate\Support\Facades\Schedule::command('push:pengingat')->everyFiveMinutes()->withoutOverlapping();
