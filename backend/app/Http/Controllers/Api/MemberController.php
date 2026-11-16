<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\MemberQr;
use App\Models\Term;
use App\Support\CardPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MemberController extends Controller
{
    public function summary(Request $r, Member $member)
    {
        $user = $r->user();
        abort_unless($user->isAdmin() || $user->member_id === $member->id, 403);

        $term = Term::where('organization_id', $member->organization_id)->where('is_current', true)->first();
        $rows = Attendance::query()->where('member_id', $member->id)->whereNull('voided_at')
            ->when($term, fn ($q) => $q->whereHas('event', fn ($e) => $e->where('term_id', $term->id)))
            ->get();
        $total = $rows->count();
        $present = $rows->whereIn('status', ['hadir', 'telat'])->count();

        return [
            'member' => $member->only('id', 'member_no', 'name'), 'term' => $term?->name,
            'by_status' => $rows->countBy('status'), 'total' => $total,
            'attendance_rate' => $total ? round($present / $total * 100, 1) : null,
        ];
    }

    /** QR pribadi yang berputar; dirotasi bila lebih tua dari qr_rotate_seconds. */
    public function myQr(Request $r)
    {
        $memberId = $r->user()->member_id;
        abort_unless($memberId, 422, 'Akun belum terhubung ke anggota.');

        $qr = MemberQr::firstOrNew(['member_id' => $memberId]);
        if (! $qr->exists || $qr->rotated_at->diffInSeconds(now()) >= config('attendance.qr_rotate_seconds')) {
            $qr->fill(['token' => Str::random(40), 'rotated_at' => now()])->save();
        }

        return ['payload' => CardPayload::makeQr($qr->token), 'expires_in' => config('attendance.qr_ttl_seconds')];
    }
}
