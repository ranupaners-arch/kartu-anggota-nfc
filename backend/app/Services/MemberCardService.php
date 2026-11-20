<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Membership;
use App\Models\Term;
use App\Models\User;

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
            'card' => $card ? ['active' => true, 'last4' => strtoupper(substr($card->public_id, -4))] : null,
        ] + $base;
    }
}
