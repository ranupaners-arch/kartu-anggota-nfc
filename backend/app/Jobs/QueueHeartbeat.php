<?php

namespace App\Jobs;

use App\Services\HealthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Dikirim scheduler tiap menit; bila worker jalan, detaknya diperbarui (dibaca health check). */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put(HealthService::QUEUE_BEAT, now()->toIso8601String(), now()->addDay());
    }
}
