<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\Term;
use Illuminate\Http\Request;

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

        return ['data' => $events->map(function (Event $e) use ($attendance, $excuses) {
            $rows = $attendance[$e->id] ?? collect();
            $x = $excuses[$e->id] ?? null;
            $presentSessions = $rows->whereIn('status', ['hadir', 'telat'])->count();
            $sessions = max(1, $e->sessions_count);

            return $e->only('id', 'title', 'type', 'location', 'starts_at', 'ends_at', 'checkin_opens_at', 'late_after', 'checkin_closes_at', 'closed_at', 'is_mandatory') + [
                'my_status' => $rows->last()?->status, // sesi terakhir yang tercatat
                'sessions' => $sessions,
                'present_sessions' => $presentSessions,
                'excuse' => $x?->only('id', 'type', 'reason', 'status', 'review_note'),
                // Izin berlaku untuk sesi yang tidak dihadiri; tidak perlu bila semua sesi sudah dihadiri.
                'can_request_excuse' => ! $e->closed_at && $presentSessions < $sessions && (! $x || $x->status === 'pending'),
            ];
        })];
    }

    /** Pengumuman aktif untuk anggota ini (semua anggota + divisinya di periode berjalan); disematkan dulu. */
    public function announcements(Request $r)
    {
        $memberId = $this->memberId($r);
        $term = Term::where('is_current', true)->first();
        $division = $term ? \App\Models\Membership::where('member_id', $memberId)->where('term_id', $term->id)->value('division_id') : null;

        return ['data' => \App\Models\Announcement::active()->forDivision($division)->with('division:id,name')
            ->orderByDesc('pinned')->latest()->limit(20)->get()
            ->map(fn ($a) => $a->only('id', 'title', 'body', 'pinned', 'created_at') + ['division' => $a->division?->name])];
    }

    /** Tampilan kartu anggota (status e-profil/pengesahan + data kartu). */
    public function card(Request $r, \App\Services\MemberCardService $cards)
    {
        return $cards->forUser($r->user());
    }

    private function member(Request $r): \App\Models\Member
    {
        return \App\Models\Member::findOrFail($this->memberId($r));
    }

    public function selfCardInit(Request $r, \App\Services\SelfCardService $cards)
    {
        return response()->json($cards->init($this->member($r)), 201);
    }

    public function selfCardAssign(Request $r, \App\Models\Card $card, \App\Services\SelfCardService $cards)
    {
        $d = $r->validate(['hw_uid' => 'nullable|string|max:64']);
        $cards->assign($this->member($r), $card, $d['hw_uid'] ?? null);

        return app(\App\Services\MemberCardService::class)->forUser($r->user());
    }

    public function cardReplacement(Request $r, \App\Services\SelfCardService $cards)
    {
        $d = $r->validate(['reason' => 'required|in:'.implode(',', \App\Services\SelfCardService::REASONS), 'note' => 'nullable|string|max:500']);
        $cards->request($r->user(), $this->member($r), $d['reason'], $d['note'] ?? null);

        return response()->json(app(\App\Services\MemberCardService::class)->forUser($r->user()), 201);
    }

    /**
     * PDF kartu anggota sendiri untuk dicetak (desain sama dengan cetak pengurus). Hanya kartu yang
     * sudah disahkan (aktif). ?format=kartu (bawaan, printer PVC/kartu) atau a4.
     */
    public function cardPdf(Request $r, \App\Services\CardPrintService $printer)
    {
        $member = $this->member($r);
        $term = Term::where('is_current', true)->firstOrFail();
        abort_unless(\App\Models\Membership::where('member_id', $member->id)->where('term_id', $term->id)->where('status', 'active')->exists(),
            403, 'Kartu bisa dicetak setelah keanggotaan disahkan.');
        $format = in_array($r->query('format'), \App\Services\CardPrintService::FORMATS, true) ? $r->query('format') : 'kartu';
        $member->load(['memberships' => fn ($q) => $q->where('term_id', $term->id)->with('division:id,name'), 'activeCard:id,member_id,public_id']);
        [$pdf, $name] = $printer->pdf(collect([$member]), $term, \App\Models\Organization::findOrFail($r->user()->organization_id), $format);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    /** Data untuk formulir e-profil: organisasi, periode, pilihan divisi. */
    public function profileForm(Request $r, \App\Services\RegistrationService $registrations)
    {
        $org = \App\Models\Organization::findOrFail($r->user()->organization_id);
        $term = $registrations->currentTerm($org);

        return ['organization' => $org->name, 'term' => $term?->name, 'name' => $r->user()->name, 'email' => $r->user()->email,
            'divisions' => $registrations->divisions($org), 'submitted' => (bool) $r->user()->member_id];
    }

    /** Kirim e-profil (multipart; foto opsional) → status Pendaftar, menunggu pengesahan Super Admin. */
    public function submitProfile(Request $r, \App\Services\RegistrationService $registrations)
    {
        $user = $r->user();
        abort_unless($user->role === \App\Models\User::MEMBER, 403);
        $org = \App\Models\Organization::findOrFail($user->organization_id);
        $d = $r->validate($registrations->profileRules($org), $registrations->messages());
        $registrations->submitProfile($user, $d, $r->file('photo'));

        return response()->json(app(\App\Services\MemberCardService::class)->forUser($user->fresh()), 201);
    }

    /** Foto anggota milik sendiri (untuk kartu di app). */
    public function photo(Request $r)
    {
        $member = $r->user()->member;
        abort_unless($member?->photo_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($member->photo_path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($member->photo_path, headers: ['Cache-Control' => 'private, max-age=3600']);
    }

    /** Rapor kehadiran PDF periode berjalan untuk anggota sendiri. */
    public function reportCard(Request $r, \App\Services\ReportCardService $cards)
    {
        $member = \App\Models\Member::findOrFail($this->memberId($r));
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
            'warning' => $term ? app(\App\Services\StatsService::class)->memberRisk($memberId, $term) : null,
            // Denda absen pakai QR (kartu tertinggal) periode ini.
            'fines' => app(\App\Services\FineService::class)->forMember($memberId, $term?->id),
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
