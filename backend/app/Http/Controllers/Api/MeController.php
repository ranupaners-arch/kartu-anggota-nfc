<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\Card;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\AchievementService;
use App\Services\CardPrintService;
use App\Services\CertificateService;
use App\Services\DuesService;
use App\Services\FineService;
use App\Services\LeaderboardService;
use App\Services\MemberCardService;
use App\Services\MemberNotificationService;
use App\Services\ProfileChangeService;
use App\Services\RegistrationService;
use App\Services\ReportCardService;
use App\Services\RsvpService;
use App\Services\SelfCardService;
use App\Services\StatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Data untuk anggota yang login (app sisi anggota). */
class MeController extends Controller
{
    /** Kegiatan periode berjalan yang ia menjadi pesertanya: yang akan datang/berlangsung + 30 hari terakhir. */
    public function events(Request $r)
    {
        $memberId = $this->memberId($r);
        $term = Term::where('is_current', true)->first();
        if (! $term) {
            return ['data' => []];
        }

        $events = Event::withCount('sessions')->where('term_id', $term->id)->where('ends_at', '>=', now()->subDays(30))
            ->orderBy('starts_at')->get()
            ->filter(fn (Event $e) => $e->targetMembers()->whereKey($memberId)->exists())->values();

        // Kegiatan multi-sesi: beberapa baris per kegiatan (satu per sesi).
        $attendance = Attendance::whereIn('event_id', $events->pluck('id'))->where('member_id', $memberId)->whereNull('voided_at')
            ->orderBy('session_id')->get()->groupBy('event_id');
        $excuses = ExcuseRequest::whereIn('event_id', $events->pluck('id'))->where('member_id', $memberId)->get()->keyBy('event_id');

        $rsvps = app(RsvpService::class)->forMember($events, $memberId);

        return ['data' => $events->map(function (Event $e) use ($attendance, $excuses, $rsvps) {
            $rows = $attendance[$e->id] ?? collect();
            $x = $excuses[$e->id] ?? null;
            $presentSessions = $rows->whereIn('status', ['hadir', 'telat'])->count();
            $sessions = max(1, $e->sessions_count);

            return $e->only('id', 'title', 'type', 'location', 'starts_at', 'ends_at', 'checkin_opens_at', 'late_after', 'checkin_closes_at', 'closed_at', 'is_mandatory') + [
                'my_status' => $rows->last()?->status, // sesi terakhir yang tercatat
                'sessions' => $sessions,
                'present_sessions' => $presentSessions,
                'rsvp' => $rsvps[$e->id] ?? null,
                'excuse' => $x?->only('id', 'type', 'reason', 'status', 'review_note'),
                // Izin berlaku untuk sesi yang tidak dihadiri; tidak perlu bila semua sesi sudah dihadiri.
                'can_request_excuse' => ! $e->closed_at && $presentSessions < $sessions && (! $x || $x->status === 'pending'),
            ];
        })];
    }

    public function rsvp(Request $r, Event $event, RsvpService $rsvps)
    {
        $d = $r->validate(['going' => 'required|boolean']);
        $memberId = $this->memberId($r);
        $rsvps->respond($event, $memberId, (bool) $d['going']);

        return $rsvps->forMember(collect([$event->fresh()]), $memberId)[$event->id];
    }

    /** Pengumuman aktif untuk anggota ini (semua anggota + divisinya di periode berjalan); disematkan dulu. */
    public function announcements(Request $r)
    {
        $memberId = $this->memberId($r);
        $term = Term::where('is_current', true)->first();
        $division = $term ? Membership::where('member_id', $memberId)->where('term_id', $term->id)->value('division_id') : null;

        return ['data' => Announcement::active()->forDivision($division)->with('division:id,name')
            ->orderByDesc('pinned')->latest()->limit(20)->get()
            ->map(fn ($a) => $a->only('id', 'title', 'body', 'pinned', 'created_at') + ['division' => $a->division?->name])];
    }

    /** Kotak notifikasi anggota (lonceng di app). */
    public function notifications(Request $r, MemberNotificationService $notifications)
    {
        return ['data' => $notifications->forMember($this->memberId($r))];
    }

    /** Poin & lencana keaktifan periode berjalan (hanya milik sendiri). */
    public function achievements(Request $r, AchievementService $achievements)
    {
        return $achievements->forMember($this->memberId($r));
    }

    /** Sertifikat milik sendiri yang masih berlaku. */
    public function certificates(Request $r, CertificateService $certificates)
    {
        return ['data' => Certificate::with('event')->where('member_id', $this->memberId($r))->whereNull('revoked_at')->latest('issued_at')->latest('id')->get()
            ->map(fn (Certificate $c) => ['id' => $c->id, 'event' => $c->event->title, 'date' => $c->event->starts_at, 'number' => $certificates->number($c, $c->event),
                'code' => $c->displayCode(), 'issued_at' => $c->issued_at])];
    }

    public function certificatePdf(Request $r, Certificate $certificate, CertificateService $certificates)
    {
        abort_unless($certificate->member_id === $this->memberId($r), 404);
        [$pdf, $name] = $certificates->pdf($certificate);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    public function leaderboard(Request $r, LeaderboardService $board)
    {
        return $board->forMember($this->member($r));
    }

    public function setLeaderboard(Request $r, LeaderboardService $board)
    {
        $d = $r->validate(['opt_in' => 'required|boolean']);
        $board->setOptIn($member = $this->member($r), (bool) $d['opt_in']);

        return $board->forMember($member->fresh());
    }

    /** Tampilan kartu anggota (status e-profil/pengesahan + data kartu). */
    public function card(Request $r, MemberCardService $cards)
    {
        return $cards->forUser($r->user());
    }

    /** Riwayat kartu anggota sendiri (kartu & laporan kartu). */
    public function cardHistory(Request $r, MemberCardService $cards)
    {
        return $cards->history($this->member($r));
    }

    /** Cek kartu NFC yang ditempel ke HP anggota: asli, aktif, dan milik sendiri? */
    public function cardCheck(Request $r, MemberCardService $cards)
    {
        $d = $r->validate(['payload' => 'required|string|max:300']);

        return $cards->check($this->member($r), $d['payload']);
    }

    /** Status perubahan data diri: data saat ini, pengajuan menunggu, keputusan terakhir. */
    public function profileChange(Request $r, ProfileChangeService $changes)
    {
        return $changes->status($this->member($r));
    }

    /** Ajukan perubahan nama / no. HP / foto (berlaku setelah disetujui Super Admin). */
    public function requestProfileChange(Request $r, ProfileChangeService $changes)
    {
        $d = $r->validate([
            'name' => 'nullable|string|max:150',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{6,30}$/'],
            'reason' => 'nullable|string|max:300',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ], ['phone.regex' => 'No. HP hanya boleh berisi angka, spasi, +, -, dan tanda kurung.']);
        $member = $this->member($r);
        $changes->request($member, $r->user(), $d, $r->file('photo'));

        return response()->json($changes->status($member->fresh()), 201);
    }

    private function member(Request $r): Member
    {
        return Member::findOrFail($this->memberId($r));
    }

    public function selfCardInit(Request $r, SelfCardService $cards)
    {
        return response()->json($cards->init($this->member($r)), 201);
    }

    public function selfCardAssign(Request $r, Card $card, SelfCardService $cards)
    {
        $d = $r->validate(['hw_uid' => 'nullable|string|max:64']);
        $cards->assign($this->member($r), $card, $d['hw_uid'] ?? null);

        return app(MemberCardService::class)->forUser($r->user());
    }

    public function cardReplacement(Request $r, SelfCardService $cards)
    {
        $d = $r->validate(['reason' => 'required|in:'.implode(',', SelfCardService::REASONS), 'note' => 'nullable|string|max:500']);
        $cards->request($r->user(), $this->member($r), $d['reason'], $d['note'] ?? null);

        return response()->json(app(MemberCardService::class)->forUser($r->user()), 201);
    }

    /**
     * PDF kartu anggota sendiri untuk dicetak (desain sama dengan cetak pengurus). Hanya kartu yang
     * sudah disahkan (aktif). ?format=kartu (bawaan, printer PVC/kartu) atau a4.
     */
    public function cardPdf(Request $r, CardPrintService $printer)
    {
        $member = $this->member($r);
        $term = Term::where('is_current', true)->firstOrFail();
        abort_unless(Membership::where('member_id', $member->id)->where('term_id', $term->id)->where('status', 'active')->exists(),
            403, 'Kartu bisa dicetak setelah keanggotaan disahkan.');
        $format = in_array($r->query('format'), CardPrintService::FORMATS, true) ? $r->query('format') : 'kartu';
        $member->load(['memberships' => fn ($q) => $q->where('term_id', $term->id)->with('division:id,name'), 'activeCard:id,member_id,public_id']);
        [$pdf, $name] = $printer->pdf(collect([$member]), $term, Organization::findOrFail($r->user()->organization_id), $format);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    /** Data untuk formulir e-profil: organisasi, periode, pilihan divisi. */
    public function profileForm(Request $r, RegistrationService $registrations)
    {
        $org = Organization::findOrFail($r->user()->organization_id);
        $term = $registrations->currentTerm($org);

        return ['organization' => $org->name, 'term' => $term?->name, 'name' => $r->user()->name, 'email' => $r->user()->email,
            'divisions' => $registrations->divisions($org), 'submitted' => (bool) $r->user()->member_id];
    }

    /** Kirim e-profil (multipart; foto opsional) → status Pendaftar, menunggu pengesahan Super Admin. */
    public function submitProfile(Request $r, RegistrationService $registrations)
    {
        $user = $r->user();
        abort_unless($user->role === User::MEMBER, 403);
        $org = Organization::findOrFail($user->organization_id);
        $d = $r->validate($registrations->profileRules($org), $registrations->messages());
        $registrations->submitProfile($user, $d, $r->file('photo'));

        return response()->json(app(MemberCardService::class)->forUser($user->fresh()), 201);
    }

    /** Foto anggota milik sendiri (untuk kartu di app). */
    public function photo(Request $r)
    {
        $member = $r->user()->member;
        abort_unless($member?->photo_path && Storage::disk('local')->exists($member->photo_path), 404);

        return Storage::disk('local')->response($member->photo_path, headers: ['Cache-Control' => 'private, max-age=3600']);
    }

    /** Rapor kehadiran PDF periode berjalan untuk anggota sendiri. */
    public function reportCard(Request $r, ReportCardService $cards)
    {
        $member = Member::findOrFail($this->memberId($r));
        $term = Term::where('is_current', true)->firstOrFail();
        [$pdf, $name] = $cards->pdf($member, $term);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    /** Ringkasan kehadiran periode berjalan + riwayat terbaru. */
    public function history(Request $r)
    {
        $memberId = $this->memberId($r);
        $term = Term::where('is_current', true)->first();
        $rows = Attendance::with('event:id,title,starts_at,term_id')->where('member_id', $memberId)->whereNull('voided_at')
            ->when($term, fn ($q) => $q->whereHas('event', fn ($e) => $e->where('term_id', $term->id)))
            ->get()->sortByDesc(fn ($a) => $a->event?->starts_at)->values();

        $byStatus = array_fill_keys(['hadir', 'telat', 'izin', 'sakit', 'alpa'], 0);
        foreach ($rows as $a) {
            $byStatus[$a->status] = ($byStatus[$a->status] ?? 0) + 1;
        }
        $total = array_sum($byStatus);

        return [
            'term' => $term?->name,
            'by_status' => $byStatus,
            'total' => $total,
            'attendance_rate' => $total ? round(($byStatus['hadir'] + $byStatus['telat']) / $total * 100, 1) : null,
            // Peringatan untuk anggota sendiri (aturan sama dengan daftar "Perlu perhatian" di dashboard).
            'warning' => $term ? app(StatsService::class)->memberRisk($memberId, $term) : null,
            // Denda absen pakai QR (kartu tertinggal) periode ini.
            'fines' => app(FineService::class)->forMember($memberId, $term?->id),
            'dues' => app(DuesService::class)->forMember($memberId, $term?->id),
            // Rekap per bulan (urut waktu) untuk grafik kehadiran di app.
            'monthly' => $rows->filter(fn (Attendance $a) => $a->event?->starts_at)
                ->groupBy(fn (Attendance $a) => $a->event->starts_at->timezone(config('app.timezone'))->format('Y-m'))
                ->sortKeys()
                ->map(fn ($g, $month) => ['month' => $month, 'total' => $g->count()]
                    + collect(['hadir', 'telat', 'izin', 'sakit', 'alpa'])->mapWithKeys(fn ($s) => [$s => $g->where('status', $s)->count()])->all())
                ->values(),
            'recent' => $rows->take(50)->map(fn (Attendance $a) => [
                'event' => $a->event?->title, 'date' => $a->event?->starts_at, 'status' => $a->status, 'source' => $a->source,
            ]),
        ];
    }

    private function memberId(Request $r): int
    {
        return $r->user()->member_id ?? abort(422, 'Akun belum terdaftar sebagai anggota. Isi e-profil dulu di tab Kartu.');
    }
}
