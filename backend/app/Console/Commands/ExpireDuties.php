<?php

namespace App\Console\Commands;

use App\Services\DutyService;
use Illuminate\Console\Command;

/** Akhiri tugas petugas/pengurus yang periodenya lewat atau tanggal berakhirnya terlampaui. */
class ExpireDuties extends Command
{
    protected $signature = 'tugas:kedaluwarsa';

    protected $description = 'Akhiri tugas petugas/pengurus yang masa berlakunya habis (akun kembali jadi anggota)';

    public function handle(DutyService $duties): int
    {
        $n = $duties->expire();
        $this->info("{$n} tugas berakhir.");

        return self::SUCCESS;
    }
}
