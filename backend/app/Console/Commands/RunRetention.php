<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\PrivacyService;
use Illuminate\Console\Command;

/** Retensi data (PRD §13): alasan & lampiran izin lama, data anggota lama, berkas ekspor lama. */
class RunRetention extends Command
{
    protected $signature = 'app:retention {--dry-run : Hanya hitung, tidak menghapus}';

    protected $description = 'Hapus data pribadi yang melewati masa retensi tiap organisasi';

    public function handle(PrivacyService $privacy): int
    {
        foreach (Organization::all() as $org) {
            $n = $privacy->retention($org, (bool) $this->option('dry-run'));
            $this->line(sprintf('%s: %d izin, %d anggota, %d ekspor, %d tamu%s', $org->name, $n['excuses'], $n['members'], $n['exports'], $n['guests'],
                $this->option('dry-run') ? ' (simulasi)' : ''));
        }

        return self::SUCCESS;
    }
}
