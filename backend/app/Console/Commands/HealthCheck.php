<?php

namespace App\Console\Commands;

use App\Services\HealthService;
use Illuminate\Console\Command;

/** Health check untuk Docker/cron: kode keluar 0 bila tidak ada pemeriksaan yang gagal. */
class HealthCheck extends Command
{
    protected $signature = 'app:health {--json : Keluaran JSON}';

    protected $description = 'Periksa database, cache, penyimpanan, queue worker, dan scheduler';

    public function handle(HealthService $health): int
    {
        $r = $health->check();
        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($r['checks'] as $name => $c) {
                $this->line(sprintf('%-4s %-10s %s', strtoupper($c['status']), $name, $c['message']));
            }
            $this->line('Status: '.strtoupper($r['status']));
        }

        return $r['status'] === 'fail' ? self::FAILURE : self::SUCCESS;
    }
}
