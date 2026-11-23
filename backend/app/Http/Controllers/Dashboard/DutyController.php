<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\Duty;
use App\Models\Member;
use App\Models\Term;
use App\Models\User;
use App\Services\DutyService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Super Admin mengatur tugas petugas/pengurus (per periode) pada akun anggota. */
class DutyController extends Controller
{
    public function __construct(private DutyService $duties) {}

    public function index()
    {
        $term = Term::where('is_current', true)->first();
        $present = fn (Duty $d) => [
            'id' => $d->id, 'type' => $d->type, 'label' => DutyService::LABELS[$d->type] ?? $d->type, 'division' => $d->division?->name,
            'ends_on' => $d->ends_on?->toDateString(), 'created_at' => $d->created_at, 'ended_at' => $d->ended_at, 'end_reason' => $d->end_reason,
            'granted_by' => $d->granter?->name, 'term' => $d->term?->name,
            'user' => $d->user?->only('id', 'name', 'email'),
            'member' => $d->user?->member ? ['id' => $d->user->member->id, 'member_no' => $d->user->member->member_no] : null,
        ];
        // Tugas periode sebelumnya yang berakhir karena ganti periode & belum diperpanjang.
        $activeUsers = Duty::open()->when($term, fn ($q) => $q->where('term_id', $term->id))->pluck('user_id');
        $previous = $term ? Duty::with('user.member:id,member_no', 'division:id,name', 'term:id,name', 'granter:id,name')
            ->where('term_id', '!=', $term->id)->where('end_reason', 'Periode berganti')->whereNotIn('user_id', $activeUsers)
            ->latest('ended_at')->limit(100)->get()->unique('user_id')->values() : collect();

        return Inertia::render('duties/index', [
            'term' => $term?->name,
            'active' => Duty::open()->with('user.member:id,member_no', 'division:id,name', 'term:id,name', 'granter:id,name')
                ->when($term, fn ($q) => $q->where('term_id', $term->id))->orderBy('type')->get()->map($present),
            'previous' => $previous->map($present),
            'history' => Duty::with('user.member:id,member_no', 'division:id,name', 'term:id,name', 'granter:id,name')
                ->whereNotNull('ended_at')->latest('ended_at')->limit(30)->get()->map($present),
        ]);
    }

    /** Beri tugas dari halaman detail anggota (akun app anggota itu). */
    public function grant(Request $r, Member $member)
    {
        $d = $r->validate([
            'type' => 'required|in:'.implode(',', Duty::TYPES),
            'division_id' => 'nullable|integer',
            'ends_on' => 'nullable|date',
        ]);
        $user = User::where('member_id', $member->id)->whereNull('disabled_at')->where('role', '!=', User::SUPER_ADMIN)->first();
        abort_unless($user, 422, 'Anggota ini belum punya akun app aktif. Buat akun app dulu (Anggota → Buat akun app).');
        $duty = $this->duties->grant($r->user(), $user, $d['type'], $d['division_id'] ?? null, $d['ends_on'] ?? null);

        return back()->with('status', "{$member->name} kini ".DutyService::LABELS[$duty->type].'. Ia perlu masuk lagi di app untuk membuka menu tugas.');
    }

    public function end(Request $r, Duty $duty)
    {
        $d = $r->validate(['reason' => 'required|string|max:300'], ['reason.required' => 'Tulis alasan (mis. mengundurkan diri, tidak bertugas lagi).']);
        $this->duties->end($r->user(), $duty, $d['reason']);

        return back()->with('status', 'Tugas dicabut. Akun kembali sebagai anggota dan dikeluarkan dari HP-nya.');
    }

    /** Perpanjang tugas periode lalu ke periode berjalan (centang beberapa sekaligus). */
    public function renew(Request $r)
    {
        $d = $r->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer']);
        $n = 0;
        foreach (Duty::whereIn('id', $d['ids'])->whereNotNull('ended_at')->get() as $old) {
            $this->duties->renew($r->user(), $old);
            $n++;
        }

        return back()->with('status', "{$n} tugas diperpanjang ke periode berjalan.");
    }

    /** Data tugas untuk halaman detail anggota. */
    public static function forMember(Member $member): array
    {
        $user = User::where('member_id', $member->id)->whereNull('disabled_at')->first();
        $duty = $user ? app(DutyService::class)->current($user) : null;

        return [
            'hasAccount' => (bool) $user,
            'isSuperAdmin' => $user?->role === User::SUPER_ADMIN,
            'current' => $duty ? [
                'id' => $duty->id, 'type' => $duty->type, 'label' => DutyService::LABELS[$duty->type] ?? $duty->type,
                'division' => $duty->division?->name, 'ends_on' => $duty->ends_on?->toDateString(), 'created_at' => $duty->created_at,
            ] : null,
            'divisions' => Division::orderBy('name')->get(['id', 'name']),
        ];
    }
}
