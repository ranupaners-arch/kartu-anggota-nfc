<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Device;
use App\Models\Event;
use App\Models\HeldScan;
use App\Models\User;
use App\Support\CardPayload;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Perangkat dicabut, peran diubah, atau akun dinonaktifkan: scan offline yang belum terkirim tidak otomatis diterima, tetapi ditahan untuk
 * ditinjau. Token lama hanya berlaku untuk unggahan ini dan hanya beberapa hari.
 */
class HeldScanService
{
    /** Keluarkan user dari semua perangkat, tetapi scan yang belum terkirim masih bisa dititipkan untuk ditinjau. */
    public function signOut(User $user): void
    {
        DB::transaction(function () use ($user) {
            foreach ($user->tokens()->get() as $token) {
                Device::withoutGlobalScopes()->where('user_id', $user->id)->where('token_id', $token->id)
                    ->update(['held_token_hash' => $token->token, 'held_at' => now()]);
            }
            $user->tokens()->delete();
        });
    }

    /** Perangkat pemilik token yang aksesnya berakhir ini, atau null bila token tidak berhak mengunggah. */
    public function deviceFor(?string $bearer): ?Device
    {
        if (! $bearer || ! preg_match('/^(\d{1,18})\|(.+)$/', $bearer, $m)) {
            return null;
        }
        $device = Device::withoutGlobalScopes()->with('user')->where('token_id', (int) $m[1])
            ->where('held_at', '>', now()->subDays(config('attendance.held_days')))->first();

        return $device?->held_token_hash && hash_equals($device->held_token_hash, hash('sha256', $m[2])) ? $device : null;
    }

    /** @return list<array{client_uuid:string,status:string,message:string}> status: held | invalid */
    public function hold(Device $device, array $scans): array
    {
        $orgId = $device->user->organization_id;

        return DB::transaction(function () use ($device, $scans, $orgId) {
            // Kunci baris perangkat: batas jumlah & uuid ganda aman dari unggahan bersamaan.
            Device::withoutGlobalScopes()->whereKey($device->id)->lockForUpdate()->first();
            $events = Event::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', array_column($scans, 'event_id'))->pluck('id')->flip();
            $known = HeldScan::withoutGlobalScopes()->where('device_id', $device->id)->pluck('id', 'client_uuid')->all();
            $added = 0;
            $results = [];

            foreach ($scans as $s) {
                $uuid = strtolower($s['client_uuid']);
                $result = fn (string $status, string $message) => ['client_uuid' => $s['client_uuid'], 'status' => $status, 'message' => $message];
                if (isset($known[$uuid])) {
                    $results[] = $result('held', 'Menunggu tinjauan pengurus.');

                    continue;
                }
                $parsed = CardPayload::verify($s['payload']);
                $card = $parsed ? Card::withoutGlobalScopes()->where('organization_id', $orgId)->where('public_id', $parsed['public_id'])->first() : null;
                if (! $card?->member_id || ! $events->has($s['event_id'])) {
                    $results[] = $result('invalid', 'Kartu atau kegiatan tidak dikenali.');

                    continue;
                }
                abort_if(count($known) >= config('attendance.held_max'), 422, 'Terlalu banyak scan dari perangkat ini. Hubungi pengurus.');
                HeldScan::create([
                    'organization_id' => $orgId, 'device_id' => $device->id, 'user_id' => $device->user_id,
                    'access_ended_at' => $device->held_at, 'event_id' => $s['event_id'], 'member_id' => $card->member_id,
                    'session_id' => $s['session_id'] ?? null, 'client_uuid' => $uuid, 'payload' => $s['payload'],
                    'scanned_at' => LocalTime::parse($s['scanned_at']),
                ]);
                $known[$uuid] = true;
                $added++;
                $results[] = $result('held', 'Menunggu tinjauan pengurus.');
            }
            if ($added) {
                AuditLogger::log('scan.held', 'device', $device->id, ['count' => $added], $device->user_id);
            }

            return $results;
        });
    }

    public function pending(User $user): Builder
    {
        return HeldScan::query()->where('status', HeldScan::PENDING)
            ->whereHas('event', fn ($q) => $q->when($user->role === User::DIVISION_ADMIN, fn ($q) => $q
                ->where(fn ($w) => $w->whereNull('division_id')->orWhere('division_id', $user->division_id))));
    }

    /** Terima: diproses seperti scan offline biasa (aturan kartu, peserta, jendela, duplikat tetap berlaku). */
    public function accept(User $user, HeldScan $scan, string $reason): void
    {
        $this->review($user, $scan, $reason, HeldScan::ACCEPTED, function (HeldScan $s) {
            $result = app(ScanService::class)->process($s->event, [
                'payload' => $s->payload, 'source' => 'nfc', 'client_uuid' => $s->client_uuid,
                'scanned_at' => $s->scanned_at->toIso8601String(), 'session_id' => $s->session_id,
            ], $s->user, $s->device, fromBatch: true);
            if (! in_array($result['status'], ['ok', 'duplicate'], true)) {
                throw ValidationException::withMessages(['reason' => "Tidak bisa diterima: {$result['message']}"]);
            }
        });
    }

    public function reject(User $user, HeldScan $scan, string $reason): void
    {
        $this->review($user, $scan, $reason, HeldScan::REJECTED, fn () => null);
    }

    private function review(User $user, HeldScan $scan, string $reason, string $status, callable $apply): void
    {
        app(EventService::class)->authorize($user, $scan->event);

        DB::transaction(function () use ($user, $scan, $reason, $status, $apply) {
            $s = HeldScan::whereKey($scan->id)->lockForUpdate()->firstOrFail();
            abort_if($s->status !== HeldScan::PENDING, 422, 'Scan ini sudah ditinjau.');
            $apply($s);
            $s->update(['status' => $status, 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_reason' => $reason, 'payload' => null]);
            AuditLogger::log("scan.held_$status", 'held_scan', $s->id, ['reason' => $reason, 'device_id' => $s->device_id, 'event_id' => $s->event_id]);
        });
    }
}
