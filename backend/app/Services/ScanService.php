<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Card;
use App\Models\Device;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\ExcuseRequest;
use App\Models\Member;
use App\Models\MemberQr;
use App\Models\User;
use App\Support\CardPayload;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

class ScanService
{
    /**
     * Proses satu scan. Mengembalikan array respons dengan `status`:
     * ok | duplicate | blocked | invalid | not_target | outside_window | clock_drift
     *
     * @param array{payload:string,source?:string,scanned_at?:string,client_uuid?:string,session_id?:int,override_reason?:string} $in
     */
    public function process(Event $event, array $in, User $user, ?Device $device, bool $fromBatch = false): array
    {
        $source = $in['source'] ?? 'nfc';
        $now = now();
        $scannedAt = isset($in['scanned_at']) ? Carbon::parse($in['scanned_at']) : $now;

        // Idempotensi: client_uuid yang sama tidak membuat data ganda.
        if (! empty($in['client_uuid'])
            && ($existing = Attendance::with('member')->where('client_uuid', $in['client_uuid'])->first())) {
            return $this->result('duplicate', $existing->member, $existing->status, 'Scan sudah tercatat.');
        }

        if (! $fromBatch && abs($scannedAt->diffInMinutes($now)) > config('attendance.clock_drift_minutes')) {
            return $this->result('clock_drift', null, null, 'Jam perangkat berbeda terlalu jauh dari server.');
        }

        // 1. Identifikasi anggota
        $card = null;
        if ($source === 'qr') {
            $member = $this->memberFromQr($in['payload'] ?? '', $scannedAt);
            if (! $member) {
                return $this->result('invalid', null, null, 'QR tidak valid atau kedaluwarsa.');
            }
        } else {
            $parsed = CardPayload::verify($in['payload'] ?? '');
            $card = $parsed ? Card::with('member')->where('public_id', $parsed['public_id'])->first() : null;
            if (! $card || ! $card->member) {
                return $this->result('invalid', null, null, 'Kartu tidak dikenali.');
            }
            $member = $card->member;
        }

        $review = null;
        if ($card && ! $card->isUsable()) {
            // Kartu diblokir SETELAH scan offline terjadi -> tinjau, jangan dibuang diam-diam.
            $wasValidAtScan = $fromBatch && $card->revoked_at && $card->revoked_at->gt($scannedAt);
            if (! $wasValidAtScan) {
                AuditLogger::log('scan.blocked', 'card', $card->id, [
                    'event_id' => $event->id, 'status' => $card->status, 'device_id' => $device?->id,
                    'scanned_at' => $scannedAt->toIso8601String(),
                ], $user->id);

                return $this->result('blocked', $member, null, 'Kartu diblokir.');
            }
            $review = 'card_blocked_after_scan';
        }

        // 2. Target peserta
        if (! $event->targetMembers()->whereKey($member->id)->exists()) {
            return $this->result('not_target', $member, null, 'Bukan peserta kegiatan ini.');
        }

        // 3. Sesi: yang dipilih petugas, atau yang jendelanya mencakup waktu scan, atau sesi utama.
        if (isset($in['session_id'])) {
            $session = $event->sessions()->find($in['session_id']);
            if (! $session) {
                return $this->result('invalid', $member, null, 'Sesi tidak ditemukan di kegiatan ini.');
            }
        } else {
            $session = $event->currentSession($scannedAt) ?? $event->defaultSession();
        }
        $multi = $event->sessions()->count() > 1;

        // 4. Jendela check-in sesi itu
        $override = false;
        $inWindow = (! $event->closed_at || $fromBatch) && $scannedAt->between($session->checkin_opens_at, $session->checkin_closes_at);
        if (! $inWindow) {
            if (empty($in['override_reason'])) {
                return $this->result('outside_window', $member, null, $multi ? "Di luar jendela check-in sesi {$session->title}." : 'Di luar jendela check-in.', session: $session);
            }
            $override = true;
        }

        $status = $scannedAt->lte($session->late_after) ? Attendance::HADIR : Attendance::TELAT;

        // 5. Satu absensi per sesi
        $attendance = Attendance::where('session_id', $session->id)->where('member_id', $member->id)->first();
        if ($attendance && ! $attendance->voided_at && $attendance->status !== Attendance::ALPA
            && ! in_array($attendance->status, [Attendance::IZIN, Attendance::SAKIT], true)) {
            return $this->result('duplicate', $member, $attendance->status, $multi ? 'Sudah absen di sesi ini.' : 'Sudah absen.', session: $session);
        }

        $data = [
            'event_id' => $event->id, 'session_id' => $session->id, 'member_id' => $member->id,
            'card_id' => $card?->id, 'status' => $status, 'source' => $source,
            'scanned_by' => $user->id, 'device_id' => $device?->id,
            'client_uuid' => $in['client_uuid'] ?? null,
            'scanned_at' => $scannedAt, 'synced_at' => $fromBatch ? $now : null,
            'needs_review' => (bool) $review, 'review_reason' => $review,
            'override' => $override, 'voided_at' => null,
        ];

        try {
            // Anggota yang tadinya Izin/Sakit/Alpa/void lalu scan -> status mengikuti scan.
            $attendance = $attendance ? tap($attendance)->update($data) : Attendance::create($data);
        } catch (UniqueConstraintViolationException) {
            return $this->result('duplicate', $member, null, 'Sudah absen.', session: $session);
        }

        ExcuseRequest::where('event_id', $event->id)->where('member_id', $member->id)
            ->where('status', 'approved')->update(['used' => false]);

        if ($override) {
            AuditLogger::log('scan.override', 'attendance', $attendance->id, ['reason' => $in['override_reason']], $user->id);
        }
        $this->detectAnomaly($card, $event, $device, $scannedAt, $user);

        return $this->result('ok', $member, $status, $review ? 'Tercatat, perlu ditinjau admin.' : 'Berhasil.', $attendance->id, $session);
    }

    private function memberFromQr(string $token, Carbon $at): ?Member
    {
        $token = CardPayload::qrToken($token);
        $qr = $token ? MemberQr::where('token', $token)->first() : null;
        if (! $qr || $qr->rotated_at->diffInSeconds(now(), false) > config('attendance.qr_ttl_seconds')) {
            return null;
        }

        return Member::find($qr->member_id);
    }

    /** Kartu sama di-tap di event/perangkat berbeda dalam < 60 detik. */
    private function detectAnomaly(?Card $card, Event $event, ?Device $device, Carbon $at, User $user): void
    {
        if (! $card) {
            return;
        }
        $other = Attendance::where('card_id', $card->id)
            ->whereBetween('scanned_at', [$at->copy()->subSeconds(config('attendance.anomaly_seconds')), $at->copy()->addSeconds(config('attendance.anomaly_seconds'))])
            ->where(fn ($q) => $q->where('event_id', '!=', $event->id)->orWhere('device_id', '!=', $device?->id))
            ->exists();

        if ($other) {
            AuditLogger::log('scan.anomaly', 'card', $card->id, ['event_id' => $event->id, 'device_id' => $device?->id], $user->id);
        }
    }

    private function result(string $status, ?Member $member, ?string $attendanceStatus, string $message, ?int $attendanceId = null, ?EventSession $session = null): array
    {
        $member?->loadMissing('memberships.division');
        $membership = $member?->memberships?->sortByDesc('term_id')->first();

        return [
            'status' => $status,
            'attendance_status' => $attendanceStatus,
            'attendance_id' => $attendanceId,
            'message' => $message,
            'session' => $session?->only('id', 'title'),
            'member' => $member ? [
                'id' => $member->id, 'member_no' => $member->member_no, 'name' => $member->name,
                'division' => $membership?->division?->name,
                'photo_url' => $member->photoUrl('api.members.photo'),
            ] : null,
        ];
    }
}
