<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\CloseEvent;
use Illuminate\Console\Command;

class CloseEvents extends Command
{
    protected $signature = 'events:close';
    protected $description = 'Tutup kegiatan yang jendela check-in-nya selesai dan tandai Alpa otomatis';

    public function handle(CloseEvent $closer): int
    {
        Event::whereNull('closed_at')->where('checkin_closes_at', '<', now()->subMinutes(config('attendance.auto_close_grace_minutes')))
            ->each(fn (Event $e) => $this->info("Event {$e->id}: {$closer->handle($e)} baris otomatis"));

        return self::SUCCESS;
    }
}
