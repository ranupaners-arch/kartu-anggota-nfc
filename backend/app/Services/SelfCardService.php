<?php

namespace App\Services;

use App\Models\Card;
use App\Models\CardRequest;
use App\Models\Member;
use App\Models\Membership;
use App\Models\User;
use App\Support\CardPayload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Anggota menulis kartu NFC sendiri lewat app (jatah 1 kali). Kartu pengganti (hilang/rusak) lewat
 * permintaan yang disetujui Super Admin, yang menambah jatah 1. Penulisan memakai alur yang sama
 * dengan penerbitan oleh pengurus: siapkan di server → tulis & kunci kartu → tautkan.
 */
class SelfCardService
{
    private const RESERVE_MINUTES = 15;

    /** Alasan laporan: kartu hilang, kartu rusak, atau HP anggota tanpa NFC / NFC rusak. */
    public const REASONS = ['hilang', 'rusak', 'tanpa_nfc'];

    public function status(Member $member): array
    {
        $active = $this->isActiveMember($member);
        $pending = CardRequest::where('member_id', $member->id)->where('status', 'pending')->latest()->first();
        // Keputusan terakhir (30 hari) agar anggota tahu tindak lanjutnya, mis. "ambil kartu di sekretariat".
        $decided = $pending ? null : CardRequest::where('member_id', $member->id)->where('status', '!=', 'pending')
            ->where('reviewed_at', '>=', now()->subDays(30))->latest('reviewed_at')->first();

        return [
            'can_write' => $active && $member->self_card_writes_left > 0,
            'writes_left' => (int) $member->self_card_writes_left,
            'pending_request' => $pending?->only('id', 'reason', 'created_at'),
            // Lapor hilang/rusak/HP tanpa NFC selalu bisa bagi anggota aktif (selama tak ada yang menunggu).
            'can_request' => $active && ! $pending,
            'last_decision' => $decided?->only('reason', 'status', 'resolution', 'review_note', 'reviewed_at'),
        ];
    }

    /** Langkah 1: siapkan kartu baru untuk anggota ini (dipesan sementara, belum aktif). */
    public function init(Member $member): array
    {
        abort_unless($this->isActiveMember($member), 403, 'Kartu hanya bisa didaftarkan setelah keanggotaan disahkan.');
        abort_unless($member->self_card_writes_left > 0, 403, 'Jatah daftar kartu sendiri sudah dipakai. Ajukan kartu pengganti ke pengurus.');
        $card = Card::create([
            'organization_id' => $member->organization_id, 'public_id' => (string) Str::ulid(),
            'key_id' => config('attendance.card_active_key'), 'status' => Card::UNASSIGNED,
        ]);
        Cache::put("selfcard:{$card->id}", $member->id, now()->addMinutes(self::RESERVE_MINUTES));

        return ['id' => $card->id, 'public_id' => $card->public_id, 'payload' => CardPayload::make($card), 'ntag' => CardPayload::ntagPassword($card)];
    }

    /** Langkah 2 (setelah penulisan berhasil): aktifkan, blokir kartu lama, kurangi jatah. */
    public function assign(Member $member, Card $card, ?string $hwUid): Card
    {
        abort_unless(Cache::get("selfcard:{$card->id}") === $member->id, 403, 'Kartu ini bukan milik Anda atau sudah kedaluwarsa. Ulangi dari awal.');
        abort_unless($card->status === Card::UNASSIGNED, 409, 'Kartu sudah dipakai.');

        DB::transaction(function () use ($member, $card, $hwUid) {
            $fresh = Member::whereKey($member->id)->lockForUpdate()->first();
            abort_unless($fresh->self_card_writes_left > 0, 403, 'Jatah daftar kartu sendiri sudah dipakai.');
            Card::where('member_id', $member->id)->where('status', Card::ACTIVE)->update(['status' => Card::BLOCKED, 'revoked_at' => now()]);
            $card->update([
                'member_id' => $member->id, 'status' => Card::ACTIVE, 'issued_at' => now(),
                'hw_uid_hash' => $hwUid ? hash('sha256', strtoupper($hwUid)) : null,
            ]);
            $fresh->decrement('self_card_writes_left');
            AuditLogger::log('card.self_issued', 'card', $card->id, ['member_id' => $member->id]);
        });
        Cache::forget("selfcard:{$card->id}");

        return $card->fresh();
    }

    /** Ajukan kartu pengganti. "hilang" langsung memblokir kartu aktif agar tak disalahgunakan. */
    public function request(User $user, Member $member, string $reason, ?string $note): CardRequest
    {
        abort_unless($this->isActiveMember($member), 403, 'Keanggotaan belum aktif.');
        abort_if(CardRequest::where('member_id', $member->id)->where('status', 'pending')->exists(), 422, 'Masih ada permintaan yang menunggu keputusan pengurus.');

        return DB::transaction(function () use ($user, $member, $reason, $note) {
            if ($reason === 'hilang') {
                Card::where('member_id', $member->id)->where('status', Card::ACTIVE)->update(['status' => Card::LOST, 'revoked_at' => now()]);
            }
            $req = CardRequest::create(['member_id' => $member->id, 'user_id' => $user->id, 'reason' => $reason, 'note' => $note]);
            AuditLogger::log('card.replacement_requested', 'card_request', $req->id, ['reason' => $reason]);
            Notifier::later('newCardRequest', $req->id);

            return $req;
        });
    }

    /**
     * Super Admin memutuskan: setujui dengan tindak lanjut "self" (jatah daftar sendiri +1) atau "admin"
     * (pengurus menerbitkan kartu lewat app petugas; catatan mis. tempat pengambilan), atau tolak.
     */
    public function review(User $admin, CardRequest $req, bool $approve, ?string $note, string $resolution = 'self'): CardRequest
    {
        abort_unless($req->status === 'pending', 422, 'Permintaan sudah diputuskan.');
        DB::transaction(function () use ($admin, $req, $approve, $note, $resolution) {
            $req->update(['status' => $approve ? 'approved' : 'rejected', 'resolution' => $approve ? $resolution : null,
                'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_note' => $note]);
            if ($approve && $resolution === 'self') {
                Member::whereKey($req->member_id)->update(['self_card_writes_left' => DB::raw('least(self_card_writes_left + 1, 3)')]);
            }
            AuditLogger::log('card.replacement_'.($approve ? 'approved' : 'rejected'), 'card_request', $req->id);
            Notifier::later('cardRequestDecided', $req->id);
        });

        return $req->fresh();
    }

    private function isActiveMember(Member $member): bool
    {
        return Membership::where('member_id', $member->id)->where('status', 'active')
            ->whereHas('term', fn ($t) => $t->where('is_current', true))->exists();
    }
}
