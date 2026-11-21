<?php

namespace App\Jobs;

use App\Services\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Kirim satu pemberitahuan alur kartu (hanya membawa nama kejadian + ID, ringan diserialisasi). */
class SendNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public string $event, public int $id) {}

    public function handle(Notifier $notifier): void
    {
        $notifier->{$this->event}($this->id);
    }
}
