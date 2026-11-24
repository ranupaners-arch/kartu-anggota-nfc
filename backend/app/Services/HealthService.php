<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pemeriksaan kesehatan sistem: database, cache, penyimpanan, queue worker, scheduler.
 * Status tiap pemeriksaan: ok | warn | fail. Keseluruhan: fail bila ada yang fail.
 */
class HealthService
{
    public const QUEUE_BEAT = 'health:queue-beat';

    public const SCHEDULER_BEAT = 'health:scheduler-beat';

    public const BOOTED_AT = 'health:first-seen';

    /** Detak lebih tua dari ini = proses dianggap mati. */
    public const STALE_MINUTES = 5;

    /** @return array{status: string, checks: array<string, array{status: string, message: string, ms?: float}>, checked_at: string} */
    public function check(): array
    {
        $checks = [
            'database' => $this->timed(fn () => DB::select('select 1') ? 'Terhubung.' : throw new \RuntimeException('Tidak ada respons.')),
            'cache' => $this->timed(function () {
                $key = 'health:probe:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                $ok = Cache::get($key) === 'ok';
                Cache::forget($key);

                return $ok ? 'Baca/tulis berhasil ('.config('cache.default').').' : throw new \RuntimeException('Nilai tidak terbaca kembali.');
            }),
            'storage' => $this->timed(function () {
                $path = 'health/'.Str::random(8);
                Storage::disk('local')->put($path, 'ok');
                Storage::disk('local')->delete($path);

                return 'Bisa ditulis.';
            }),
            'queue' => $this->beat(self::QUEUE_BEAT, 'Queue worker', $this->queueSize()),
            'scheduler' => $this->beat(self::SCHEDULER_BEAT, 'Scheduler'),
        ];

        $statuses = array_column($checks, 'status');

        return [
            'status' => in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok'),
            'checks' => $checks,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function timed(callable $probe): array
    {
        $t = hrtime(true);
        try {
            $message = $probe();
            $status = 'ok';
        } catch (Throwable $e) {
            report($e);
            [$status, $message] = ['fail', 'Gagal: '.class_basename($e)];
        }

        return ['status' => $status, 'message' => $message, 'ms' => round((hrtime(true) - $t) / 1e6, 1)];
    }

    /** Detak proses latar (worker/scheduler). Belum ada detak sesaat setelah start = warn, bukan fail. */
    private function beat(string $key, string $label, ?int $queueSize = null): array
    {
        try {
            $raw = Cache::get($key);
            $firstSeen = Carbon::parse(Cache::rememberForever(self::BOOTED_AT, fn () => now()->toIso8601String()));
        } catch (Throwable) {
            return ['status' => 'fail', 'message' => "$label: cache tidak terbaca."];
        }
        $suffix = $queueSize === null ? '' : " Antrean: $queueSize job.";
        if (! $raw) {
            $young = $firstSeen->gt(now()->subMinutes(self::STALE_MINUTES));

            return ['status' => $young ? 'warn' : 'fail', 'message' => ($young ? "$label belum mengirim detak (baru mulai)." : "$label tidak pernah mengirim detak.").$suffix];
        }
        $age = (int) Carbon::parse($raw)->diffInSeconds(now());
        $stale = $age > self::STALE_MINUTES * 60;

        return ['status' => $stale ? 'fail' : 'ok', 'message' => ($stale ? "$label tidak aktif: detak terakhir $age detik lalu." : "$label aktif (detak $age detik lalu).").$suffix];
    }

    private function queueSize(): ?int
    {
        try {
            return Queue::size();
        } catch (Throwable) {
            return null;
        }
    }
}
