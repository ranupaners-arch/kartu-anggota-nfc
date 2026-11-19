<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Tap Monolog: tambahkan id pengguna & organisasi yang sedang login ke setiap baris log.
 * Hanya id (bukan nama/email) agar log tidak memuat data pribadi.
 */
class AddUserContext
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(function (LogRecord $record): LogRecord {
            if (! app()->bound('auth')) {
                return $record;
            }
            foreach (['web', 'sanctum'] as $guard) {
                $g = auth()->guard($guard);
                if ($g->hasUser()) { // tidak memicu pemuatan user baru
                    $u = $g->user();

                    return $record->with(extra: $record->extra + ['user_id' => $u->id, 'organization_id' => $u->organization_id]);
                }
            }

            return $record;
        });
    }
}
