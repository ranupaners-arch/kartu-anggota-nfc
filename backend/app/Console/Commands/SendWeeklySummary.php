<?php

namespace App\Console\Commands;

use App\Services\MailStatus;
use App\Services\WeeklySummaryService;
use Illuminate\Console\Command;

class SendWeeklySummary extends Command
{
    protected $signature = 'ringkasan:mingguan';

    protected $description = 'Kirim ringkasan mingguan kehadiran ke email pengurus (bila email server sudah diatur)';

    public function handle(WeeklySummaryService $summaries): int
    {
        if (! MailStatus::enabled()) {
            $this->warn('Email server belum diatur (MAIL_MAILER='.MailStatus::mailer().'); ringkasan tidak dikirim.');

            return self::SUCCESS;
        }
        $r = $summaries->sendAll();
        $this->info("Ringkasan terkirim: {$r['sent']}, dilewati (tidak ada isi): {$r['skipped']}.");

        return self::SUCCESS;
    }
}
