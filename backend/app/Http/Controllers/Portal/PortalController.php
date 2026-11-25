<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Api\ExcuseController as ApiExcuse;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MemberController as ApiMember;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Membership;
use App\Models\PushSubscription;
use App\Models\Term;
use App\Services\AuditLogger;
use App\Services\CertificateService;
use App\Services\LeaderboardService;
use App\Services\MemberCardService;
use App\Services\Push\PushService;
use App\Services\RegistrationService;
use App\Services\ReportCardService;
use App\Services\RsvpService;
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
            'card' => app(MemberCardService::class)->forUser($r->user(), 'portal.photo'),
            // QR hanya untuk anggota yang sudah disahkan (aktif).
            'qr' => fn () => $r->user()->member_id && Membership::where('member_id', $r->user()->member_id)
                ->whereHas('term', fn ($t) => $t->where('is_current', true))->where('status', 'active')->exists()
                ? $members->myQr($r) + ['generated_at' => now()->toIso8601String()] : null,
        ]);
    }

    /** Formulir e-profil (web) bagi akun yang belum terdaftar sebagai anggota. */
    public function profileForm(Request $r, RegistrationService $registrations)
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

    public function submitProfile(Request $r, RegistrationService $registrations)
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

    public function rsvp(Request $r, Event $event, RsvpService $rsvps)
    {
        $result = $this->me->rsvp($r, $event, $rsvps);

        return back()->with('status', match ($result['status']) {
            'going' => 'Anda terdaftar ikut.',
            'waitlist' => "Kuota penuh: Anda di daftar tunggu nomor {$result['position']}.",
            default => 'Anda tercatat tidak ikut.',
        });
    }

    public function storeExcuse(Request $r, ApiExcuse $excuses)
    {
        $r->request->remove('member_id'); // anggota hanya untuk dirinya sendiri
        $excuses->store($r);

        return back()->with('status', 'Pengajuan izin terkirim. Pengurus akan meninjaunya.');
    }

    public function history(Request $r, LeaderboardService $board, CertificateService $certificates)
    {
        return Inertia::render('portal/history', ['history' => $this->me->history($r), 'leaderboard' => $this->me->leaderboard($r, $board), 'certificates' => $this->me->certificates($r, $certificates)['data']]);
    }

    public function leaderboard(Request $r, LeaderboardService $board)
    {
        $result = $this->me->setLeaderboard($r, $board);

        return back()->with('status', $result['opted_in'] ? (($result['me']['points'] ?? 0) > 0 ? 'Nama Anda tampil di papan peringkat.' : 'Tersimpan. Nama Anda tampil setelah mendapat poin pertama.') : 'Nama Anda tidak lagi tampil di papan peringkat.');
    }

    public function reportCard(Request $r, ReportCardService $cards)
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
            'push' => ['publicKey' => PushService::enabled() ? config('services.webpush.public_key') : null,
                'devices' => PushSubscription::where('user_id', $u->id)->count()],
        ]);
    }

    public function subscribePush(Request $r, PushService $push)
    {
        abort_unless(PushService::enabled(), 503, 'Notifikasi belum diaktifkan di server ini.');
        $d = $r->validate([
            'endpoint' => 'required|url:https|max:2000', 'keys.p256dh' => 'required|string|max:200', 'keys.auth' => 'required|string|max:100',
        ]);
        $push->subscribe($r->user(), $d, $r->userAgent());

        return response()->noContent();
    }

    public function unsubscribePush(Request $r, PushService $push)
    {
        $push->unsubscribe($r->user(), (string) $r->input('endpoint'));

        return response()->noContent();
    }

    public function testPush(Request $r, PushService $push)
    {
        $n = $push->toUsers(collect([$r->user()->id]), 'Notifikasi aktif', 'Pemberitahuan akan masuk ke perangkat ini.', '/saya', 'uji');

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
