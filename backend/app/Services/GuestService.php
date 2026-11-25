<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\Event;
use App\Models\EventGuest;
use App\Models\EventGuestCheckin;
use App\Models\EventSession;
use App\Models\User;
use App\Support\CardPayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Peserta tamu: daftar, QR, dan check-in. QR hanya berlaku untuk kegiatannya dan butuh koneksi internet. */
class GuestService
{
    public const MAX_GUESTS = 500;

    public function __construct(private EventService $events) {}

    /**
     * Satu baris satu tamu: "Nama" atau "Nama; Instansi".
     *
     * @return int jumlah tamu ditambahkan
     */
    public function add(User $by, Event $event, string $lines): int
    {
        $this->events->authorize($by, $event);
        $rows = collect(preg_split('/\R/', $lines))->map(fn ($l) => array_map('trim', explode(';', $l, 2)))->filter(fn ($r) => $r[0] !== '')->values();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Isi minimal satu nama.']);
        }
        foreach ($rows as $i => $r) {
            if (mb_strlen($r[0]) > 100 || mb_strlen($r[1] ?? '') > 100) {
                throw ValidationException::withMessages(['lines' => 'Baris '.($i + 1).': nama dan instansi maksimal 100 karakter.']);
            }
        }

        return DB::transaction(function () use ($by, $event, $rows) {
            $event = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_if($event->closed_at, 422, 'Kegiatan sudah ditutup.');
            if (EventGuest::where('event_id', $event->id)->count() + $rows->count() > self::MAX_GUESTS) {
                throw ValidationException::withMessages(['lines' => 'Maksimal '.self::MAX_GUESTS.' tamu per kegiatan.']);
            }
            foreach ($rows as $r) {
                EventGuest::create(['event_id' => $event->id, 'name' => $r[0], 'institution' => ($r[1] ?? '') ?: null, 'token' => Str::random(40), 'created_by' => $by->id]);
            }
            AuditLogger::log('guest.added', 'event', $event->id, ['count' => $rows->count()], $by->id);

            return $rows->count();
        });
    }

    public function setRevoked(User $by, EventGuest $guest, bool $revoked): void
    {
        $this->events->authorize($by, $guest->event);
        abort_if($guest->purged_at, 422, 'Data tamu ini sudah dihapus.');
        $guest->update(['revoked_at' => $revoked ? now() : null]);
        AuditLogger::log($revoked ? 'guest.revoked' : 'guest.restored', 'event', $guest->event_id, ['guest_id' => $guest->id], $by->id);
    }

    public function delete(User $by, EventGuest $guest): void
    {
        $this->events->authorize($by, $guest->event);
        DB::transaction(function () use ($by, $guest) {
            $g = EventGuest::whereKey($guest->id)->lockForUpdate()->firstOrFail();
            abort_if($g->checkins()->exists(), 422, 'Tamu ini sudah hadir; batalkan QR-nya saja.');
            $g->delete();
            AuditLogger::log('guest.deleted', 'event', $g->event_id, ['guest_id' => $g->id], $by->id);
        });
    }

    public static function payload(EventGuest $guest): string
    {
        return CardPayload::prefix().':guest:'.$guest->token;
    }

    public static function token(string $payload): ?string
    {
        $head = CardPayload::prefix().':guest:';
        $payload = trim($payload);

        return str_starts_with($payload, $head) ? (substr($payload, strlen($head)) ?: null) : null;
    }

    /** Check-in tamu; bentuk hasil sama dengan ScanService supaya app & web menampilkannya tanpa perubahan. */
    public function scan(Event $event, string $token, array $in, Carbon $at, User $user, ?Device $device, ScanContext $ctx): array
    {
        $guest = EventGuest::where('token', $token)->first();
        if (! $guest) {
            return $this->result('invalid', null, null, 'QR tamu tidak dikenali.');
        }
        if ($guest->event_id !== $event->id) {
            return $this->result('not_target', null, null, 'QR tamu ini untuk kegiatan lain.');
        }
        if (isset($in['session_id'])) {
            $session = $ctx->session((int) $in['session_id']);
            if (! $session) {
                return $this->result('invalid', $guest, null, 'Sesi tidak ditemukan di kegiatan ini.');
            }
        } else {
            $session = $event->currentSession($at) ?? $ctx->sessions->first() ?? $event->defaultSession();
        }
        $multi = $ctx->multiSession();
        $override = false;
        if ($event->closed_at || ! $at->between($session->checkin_opens_at, $session->checkin_closes_at)) {
            if (empty($in['override_reason'])) {
                return $this->result('outside_window', $guest, null, $multi ? "Di luar jendela check-in sesi {$session->title}." : 'Di luar jendela check-in.', $session);
            }
            $override = true;
        }

        return DB::transaction(function () use ($guest, $session, $multi, $override, $in, $at, $user, $device) {
            $g = EventGuest::whereKey($guest->id)->lockForUpdate()->first(); // pembatalan bersamaan harus menang
            if (! $g || $g->revoked_at) {
                return $this->result('blocked', $g, null, 'QR tamu dibatalkan.', $session);
            }
            $done = $g->checkins()->where('session_id', $session->id)->first();
            if ($done) {
                return $this->result('duplicate', $g, $done->status, $multi ? 'Sudah absen di sesi ini.' : 'Sudah absen.', $session);
            }
            $status = $at->lte($session->late_after) ? Attendance::HADIR : Attendance::TELAT;
            $g->checkins()->create(['session_id' => $session->id, 'status' => $status, 'scanned_at' => $at, 'scanned_by' => $user->id, 'device_id' => $device?->id]);
            if ($override) {
                AuditLogger::log('guest.override', 'event', $g->event_id, ['guest_id' => $g->id, 'reason' => $in['override_reason']], $user->id);
            }

            return $this->result('ok', $g, $status, 'Tamu tercatat.', $session);
        });
    }

    private function result(string $status, ?EventGuest $guest, ?string $attendanceStatus, string $message, ?EventSession $session = null): array
    {
        return [
            'status' => $status, 'attendance_status' => $attendanceStatus, 'attendance_id' => null, 'message' => $message,
            'session' => $session?->only('id', 'title'),
            'member' => $guest ? ['id' => null, 'member_no' => 'Tamu', 'name' => $guest->name, 'division' => $guest->institution, 'photo_url' => null] : null,
        ];
    }

    /** Tab di halaman kegiatan. */
    public function tab(Event $event): array
    {
        $guests = EventGuest::with('checkins.session:id,title')->where('event_id', $event->id)->orderBy('name')->orderBy('id')->get();

        return [
            'max' => self::MAX_GUESTS,
            'present' => $guests->filter(fn (EventGuest $g) => $g->checkins->isNotEmpty())->count(),
            'items' => $guests->map(fn (EventGuest $g) => $g->only('id', 'name', 'institution', 'revoked_at') + ['purged' => $g->purged_at !== null,
                'url' => $g->purged_at ? null : route('guests.show', $g->token),
                'checkins' => $g->checkins->sortBy('session_id')->map(fn (EventGuestCheckin $c) => ['session' => $c->session->title, 'status' => $c->status, 'at' => $c->scanned_at])->values()]),
        ];
    }
}
