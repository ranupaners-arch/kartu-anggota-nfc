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
        if (! $m) {
            return ['name' => $r->user()->name, 'member_no' => null, 'division' => null, 'organization' => $r->user()->organization?->name];
        }
        $term = Term::where('is_current', true)->first();
        $division = $term ? Membership::with('division:id,name')->where('member_id', $m->id)->where('term_id', $term->id)->first()?->division?->name : null;

        return ['name' => $m->name, 'member_no' => $m->member_no, 'division' => $division, 'organization' => $r->user()->organization?->name];
    }

    /** QR Saya: dirotasi server tiap menit; halaman memperbarui QR lewat polling. */
    public function qr(Request $r, ApiMember $members)
    {
        return Inertia::render('portal/qr', [
            'profile' => $this->profile($r),
            'card' => app(\App\Services\MemberCardService::class)->forUser($r->user(), 'portal.photo'),
            // QR hanya untuk anggota yang sudah disahkan (aktif).
            'qr' => fn () => $r->user()->member_id && \App\Models\Membership::where('member_id', $r->user()->member_id)
                ->whereHas('term', fn ($t) => $t->where('is_current', true))->where('status', 'active')->exists()
                ? $members->myQr($r) + ['generated_at' => now()->toIso8601String()] : null,
        ]);
    }

    /** Formulir e-profil (web) bagi akun yang belum terdaftar sebagai anggota. */
    public function profileForm(Request $r, \App\Services\RegistrationService $registrations)
    {
        if ($r->user()->member_id) {
            return redirect()->route('portal.qr');
        }
        $org = $r->user()->organization;

        return Inertia::render('portal/profile', [
            'organization' => $org->name, 'term' => $registrations->currentTerm($org)?->name,
            'name' => $r->user()->name, 'email' => $r->user()->email, 'divisions' => $registrations->divisions($org),
        ]);
    }

    public function submitProfile(Request $r, \App\Services\RegistrationService $registrations)
    {
        $org = $r->user()->organization;
        $d = $r->validate($registrations->profileRules($org), $registrations->messages());
        $registrations->submitProfile($r->user(), $d, $r->file('photo'));

        return redirect()->route('portal.qr')->with('status', 'E-profil terkirim. Menunggu pengesahan pengurus pusat.');
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
            // Notifikasi push: kunci publik VAPID (null = fitur nonaktif di server ini).
            'push' => ['publicKey' => \App\Services\Push\PushService::enabled() ? config('services.webpush.public_key') : null,
                'devices' => \App\Models\PushSubscription::where('user_id', $u->id)->count()],
        ]);
    }

    public function subscribePush(Request $r, \App\Services\Push\PushService $push)
    {
        abort_unless(\App\Services\Push\PushService::enabled(), 503, 'Notifikasi belum diaktifkan di server ini.');
        $d = $r->validate([
            'endpoint' => 'required|url:https|max:2000', 'keys.p256dh' => 'required|string|max:200', 'keys.auth' => 'required|string|max:100',
        ]);
        $push->subscribe($r->user(), $d, $r->userAgent());

        return response()->noContent();
    }

    public function unsubscribePush(Request $r, \App\Services\Push\PushService $push)
    {
        $push->unsubscribe($r->user(), (string) $r->input('endpoint'));

        return response()->noContent();
    }

    public function testPush(Request $r, \App\Services\Push\PushService $push)
    {
        $n = $push->toUsers(collect([$r->user()->id]), 'Notifikasi aktif', 'Anda akan menerima pengingat kegiatan dan pengumuman di perangkat ini.', '/saya', 'uji');

        return response()->json(['devices' => $n]);
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
