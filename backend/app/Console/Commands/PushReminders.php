<?php

namespace App\Console\Commands;

use App\Services\Push\PushService;
use Illuminate\Console\Command;

class PushReminders extends Command
{
    protected $signature = 'push:pengingat';

    protected $description = 'Kirim pengingat push ±1 jam sebelum kegiatan dimulai (portal anggota)';

    public function handle(PushService $push): int
    {
        if (! PushService::enabled()) {
            return self::SUCCESS; // nonaktif: kunci VAPID belum diisi
        }
        $r = $push->eventReminders();
        if ($r['events']) {
            $this->info("Pengingat: {$r['events']} kegiatan, {$r['devices']} perangkat.");
        }

        return self::SUCCESS;
    }
}
