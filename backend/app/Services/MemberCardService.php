<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Card;
use App\Models\CardRequest;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Term;
use App\Models\User;
use App\Support\CardPayload;

/**
 * Data kartu anggota untuk tampilan kartu di app & portal.
 * state: none (belum punya e-profil) · pending (menunggu pengesahan) · active (sah) · inactive (alumni/tidak aktif).
 */
class MemberCardService
{
    public function forUser(User $user, string $photoRoute = 'api.me.photo'): array
    {
        $member = $user->member()->first();
        $photo = $member?->photo_path ? route($photoRoute).'?v='.substr(md5($member->photo_path), 0, 8) : null;

        return $this->build($member, $user->organization?->name, $photo);
    }

    /** Pratinjau kartu seorang anggota (dashboard pengurus). */
    public function forMember(Member $member, ?string $organization): array
    {
        return $this->build($member, $organization, $member->photoUrl('members.photo'));
    }

    private function build(?Member $member, ?string $organization, ?string $photoUrl): array
    {
        $term = Term::where('is_current', true)->first();
        $base = ['organization' => $organization, 'term' => $term?->name, 'valid_until' => $term?->ends_on?->toDateString()];
        if (! $member) {
            return ['state' => 'none', 'member' => null, 'card' => null] + $base;
        }
        $ms = $term ? Membership::with('division:id,name')->where('member_id', $member->id)->where('term_id', $term->id)->first() : null;
        $state = match ($ms?->status) {
            'active' => 'active',
            'applicant', null => 'pending',
            default => 'inactive',
        };
        $card = $member->activeCard()->first();

        return [
            'state' => $state,
            'status' => $ms?->status,
            'member' => [
                'name' => $member->name, 'member_no' => $member->member_no, 'cohort' => $member->cohort,
                'division' => $ms?->division?->name, 'photo_url' => $photoUrl,
            ],
            'card' => $card ? [
                'active' => true, 'last4' => strtoupper(substr($card->public_id, -4)),
                'issued_at' => $card->issued_at?->toIso8601String(), 'issued_by' => $this->issuedBy([$card->id])[$card->id] ?? null,
            ] : null,
            // Daftar kartu NFC sendiri (jatah) & permintaan kartu pengganti.
            'self_card' => app(SelfCardService::class)->status($member),
        ] + $base;
    }

    /**
     * Cara terbit kartu dari log aktivitas: "self" (didaftarkan anggota sendiri) atau "admin" (pengurus).
     *
     * @param  list<int>  $cardIds
     * @return array<int, string>
     */
    public function issuedBy(array $cardIds): array
    {
        return AuditLog::where('entity', 'card')->whereIn('entity_id', $cardIds)
            ->whereIn('action', ['card.issued', 'card.self_issued'])->get()
            ->mapWithKeys(fn ($l) => [$l->entity_id => $l->action === 'card.self_issued' ? 'self' : 'admin'])->all();
    }

    /** Riwayat kartu anggota sendiri: semua kartu & laporan kartu beserta keputusan pengurus. */
    public function history(Member $member): array
    {
        $cards = $member->cards()->orderByDesc('issued_at')->orderByDesc('id')->get();
        $by = $this->issuedBy($cards->pluck('id')->all());

        return [
            'cards' => $cards->map(fn ($c) => [
                'last4' => strtoupper(substr($c->public_id, -4)), 'status' => $c->status,
                'issued_at' => $c->issued_at?->toIso8601String(), 'revoked_at' => $c->revoked_at?->toIso8601String(),
                'issued_by' => $by[$c->id] ?? null,
            ])->values(),
            'requests' => CardRequest::where('member_id', $member->id)->latest()->limit(20)->get()
                ->map(fn ($r) => $r->only('reason', 'status', 'note', 'review_note', 'resolution') + [
                    'created_at' => $r->created_at?->toIso8601String(), 'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                ])->values(),
        ];
    }

    /**
     * Cek kartu NFC yang ditempel anggota ke HP-nya sendiri. Tidak membuka data pemilik lain.
     * result: mine (kartu aktif Anda) · mine_inactive (kartu Anda, sudah diblokir/hilang) · other (kartu orang lain)
     *         · unknown (tanda tangan sah tapi tidak terdaftar) · invalid (bukan kartu organisasi ini / rusak).
     */
    public function check(Member $member, string $payload): array
    {
        $p = CardPayload::verify($payload);
        if (! $p) {
            return ['result' => 'invalid'];
        }
        $card = Card::where('public_id', $p['public_id'])->first();
        if (! $card || ! $card->member_id) {
            return ['result' => 'unknown'];
        }
        if ($card->member_id !== $member->id) {
            return ['result' => 'other'];
        }

        return ['result' => $card->status === 'active' ? 'mine' : 'mine_inactive', 'status' => $card->status, 'last4' => strtoupper(substr($card->public_id, -4))];
    }
}
