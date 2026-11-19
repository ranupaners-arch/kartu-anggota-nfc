<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Api\ExcuseController as ApiExcuse;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MemberController as ApiMember;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Term;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

/**
 * Portal web anggota (untuk yang tidak memakai app Android, mis. pengguna iPhone). Data diambil lewat
 * endpoint yang sama dengan app (Api\MeController dkk.), jadi isinya identik dengan di app.
 */
class PortalController extends Controller
{
    public function __construct(private MeController $me) {}

    private function profile(Request $r): array
    {
        $m = $r->user()->member;
        $term = Term::where('is_current', true)->first();
        $division = $term ? Membership::with('division:id,name')->where('member_id', $m->id)->where('term_id', $term->id)->first()?->division?->name : null;

        return ['name' => $m->name, 'member_no' => $m->member_no, 'division' => $division, 'organization' => $r->user()->organization?->name];
    }

    /** QR Saya: dirotasi server tiap menit; halaman memperbarui QR lewat polling. */
    public function qr(Request $r, ApiMember $members)
    {
        return Inertia::render('portal/qr', [
            'profile' => $this->profile($r),
            'qr' => fn () => $members->myQr($r) + ['generated_at' => now()->toIso8601String()],
        ]);
    }

    public function events(Request $r)
    {
        return Inertia::render('portal/events', [
            'events' => $this->me->events($r)['data'],
            'announcements' => $this->me->announcements($r)['data'],
        ]);
    }

    public function storeExcuse(Request $r, ApiExcuse $excuses)
    {
        $r->request->remove('member_id'); // anggota hanya untuk dirinya sendiri
        $excuses->store($r);

        return back()->with('status', 'Pengajuan izin terkirim. Pengurus akan meninjaunya.');
    }

    public function history(Request $r)
    {
        return Inertia::render('portal/history', ['history' => $this->me->history($r)]);
    }

    public function reportCard(Request $r, \App\Services\ReportCardService $cards)
    {
        return $this->me->reportCard($r, $cards);
    }

    public function account(Request $r)
    {
        $u = $r->user();

        return Inertia::render('portal/account', [
            'profile' => $this->profile($r) + ['email' => $u->email],
            'mustChangePassword' => (bool) $u->must_change_password,
            'calendarUrl' => $u->calendar_token ? route('calendar.feed', ['token' => $u->calendar_token]) : null,
        ]);
    }

    public function password(Request $r)
    {
        $r->validate([
            'current_password' => 'required|current_password:web',
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(10)->letters()->numbers()],
        ], ['password.different' => 'Password baru harus berbeda dari password sekarang.']);
        $r->user()->update(['password' => $r->input('password'), 'must_change_password' => false]);
        $r->user()->tokens()->delete(); // keluarkan app di perangkat lain
        AuditLogger::log('user.password_changed', 'user', $r->user()->id);

        return redirect()->route('portal.qr')->with('status', 'Password diganti.');
    }
}
