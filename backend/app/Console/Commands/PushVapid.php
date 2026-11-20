<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class PushVapid extends Command
{
    protected $signature = 'push:vapid';

    protected $description = 'Buat pasangan kunci VAPID untuk notifikasi push portal (salin ke .env)';

    public function handle(): int
    {
        $k = VAPID::createVapidKeys();
        $this->line('Tambahkan ke .env server lalu jalankan ulang (jangan dibagikan; kunci privat rahasia):');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$k['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$k['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:admin@organisasi.id');

        return self::SUCCESS;
    }
}
