<?php

use App\Jobs\QueueHeartbeat;
use App\Services\HealthService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('events:close')->everyMinute();
// Detak untuk health check: scheduler hidup, dan queue worker memproses job.
Schedule::call(fn () => Cache::put(HealthService::SCHEDULER_BEAT, now()->toIso8601String(), now()->addDay()))
    ->everyMinute()->name('health:scheduler-beat');
Schedule::job(new QueueHeartbeat)->everyMinute();
Schedule::command('app:retention')->dailyAt('02:30')->withoutOverlapping();
// Ringkasan mingguan untuk pengurus: Senin 07.00 WIB (hanya bila email server sudah diatur).
Schedule::command('ringkasan:mingguan')->weeklyOn(1, '07:00')->timezone('Asia/Jakarta')->withoutOverlapping();
// Pengingat push portal anggota ±1 jam sebelum kegiatan (nonaktif bila kunci VAPID kosong).
Schedule::command('push:pengingat')->everyFiveMinutes()->withoutOverlapping();

// Tugas petugas/pengurus yang habis masa berlakunya → akun kembali jadi anggota.
Schedule::command('tugas:kedaluwarsa')->hourly()->withoutOverlapping();
