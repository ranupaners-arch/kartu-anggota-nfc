<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Perbaiki waktu scan yang tersimpan terlalu awal sebesar selisih zona waktu (sebelum perbaikan LocalTime).
 * Hanya baris yang pasti: waktunya sekarang di luar jendela check-in tetapi pas bila digeser, atau scan
 * online yang selisihnya dengan waktu pencatatan sama dengan selisih zona. Status absensi tidak berubah.
 */
class FixScanTimes extends Command
{
    protected $signature = 'scan:perbaiki-waktu {--terapkan : Simpan perubahan (tanpa ini hanya simulasi)}';

    protected $description = 'Perbaiki waktu scan lama yang bergeser karena zona waktu';

    public function handle(): int
    {
        $offset = now()->getOffset();
        if ($offset === 0) {
            $this->info('Server memakai UTC: tidak ada yang bergeser.');

            return self::SUCCESS;
        }
        $fixed = $unsure = 0;
        $run = function () use ($offset, &$fixed, &$unsure) {
            Attendance::withoutGlobalScopes()->with(['session' => fn ($q) => $q->withoutGlobalScopes()])
                ->whereIn('source', ['nfc', 'qr'])->where('override', false)->whereNotNull('scanned_at')
                ->chunkById(500, function ($rows) use ($offset, &$fixed, &$unsure) {
                    foreach ($rows as $a) {
                        $shifted = $a->scanned_at->copy()->addSeconds($offset);
                        $inWindow = fn ($t) => $a->session && $t->between($a->session->checkin_opens_at, $a->session->checkin_closes_at);
                        $online = ! $a->synced_at && abs($a->created_at->diffInSeconds($shifted)) <= 600;
                        if ($inWindow($shifted) && (! $inWindow($a->scanned_at) || $online)) {
                            $fixed++;
                            if ($this->option('terapkan')) {
                                DB::table('attendances')->where('id', $a->id)->update(['scanned_at' => $shifted]);
                            }
                        } elseif (! $inWindow($a->scanned_at)) {
                            $unsure++;
                        }
                    }
                });
        };
        DB::transaction($run);
        if ($this->option('terapkan') && $fixed) {
            AuditLogger::log('scan.times_fixed', null, null, ['count' => $fixed, 'offset_seconds' => $offset]);
        }
        $this->info(($this->option('terapkan') ? 'Diperbaiki' : 'Akan diperbaiki (simulasi)').": $fixed. Tidak disentuh karena tidak pasti: $unsure.");

        return self::SUCCESS;
    }
}
