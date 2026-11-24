<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Fine;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Term;

/** Rekap absensi. Semua angka mengabaikan absensi yang di-void. */
class ReportService
{
    public const STATUSES = ['hadir', 'telat', 'izin', 'sakit', 'alpa'];

    /** Rekap satu sesi kegiatan (bawaan: sesi utama): jumlah per status, persentase, daftar hadir. */
    public function event(Event $event, ?EventSession $session = null): array
    {
        $session ??= $event->defaultSession();
        abort_unless($session->event_id === $event->id, 404);
        $attendances = Attendance::where('session_id', $session->id)->whereNull('voided_at')->get()->keyBy('member_id');
        $members = $event->targetMembers()->with(['memberships' => fn ($q) => $q->where('term_id', $event->term_id)->with('division')])
            ->orderBy('name')->get();

        // Anggota yang absen tapi bukan target (mis. override) tetap ikut dihitung.
        $extraIds = $attendances->keys()->diff($members->pluck('id'));
        if ($extraIds->isNotEmpty()) {
            $members = $members->concat(Member::with('memberships.division')->whereIn('id', $extraIds)->get());
        }

        $rows = $members->map(function (Member $m) use ($attendances) {
            $a = $attendances->get($m->id);

            return [
                'member_id' => $m->id, 'attendance_id' => $a?->id, 'member_no' => $m->member_no, 'name' => $m->name,
                'division' => $m->memberships->first()?->division?->name,
                'status' => $a?->status ?? 'belum_absen',
                'scanned_at' => $a?->scanned_at?->toIso8601String(), 'source' => $a?->source,
                'needs_review' => (bool) $a?->needs_review,
            ];
        })->values();

        return [
            'event' => $event->only('id', 'title', 'type', 'location', 'starts_at', 'closed_at'),
            'session' => $session->only('id', 'title'),
            'sessions' => $this->sessions($event),
            'summary' => $this->summary($rows->pluck('status')),
            'rows' => $rows,
        ];
    }

    /** Rekap per periode (opsional per divisi): per anggota, jumlah per status dan persentase. */
    public function term(Term $term, ?int $divisionId = null): array
    {
        $members = Member::query()
            ->whereHas('memberships', fn ($q) => $q->where('term_id', $term->id)
                ->when($divisionId, fn ($q) => $q->where('division_id', $divisionId)))
            ->with(['memberships' => fn ($q) => $q->where('term_id', $term->id)->with('division')])
            ->orderBy('name')->get();

        $counts = Attendance::query()->whereNull('voided_at')->whereIn('member_id', $members->pluck('id'))
            ->whereHas('event', fn ($q) => $q->where('term_id', $term->id))
            ->selectRaw('member_id, status, count(*) as n')->groupBy('member_id', 'status')->get()
            ->groupBy('member_id');

        // Denda absen QR per anggota (tidak termasuk jatah gratis).
        $fines = Fine::where('term_id', $term->id)->whereIn('member_id', $members->pluck('id'))->where('status', '!=', Fine::FREE)
            ->selectRaw("member_id, count(*) as n, sum(case when status = 'unpaid' then amount else 0 end) as unpaid, sum(case when status = 'paid' then amount else 0 end) as paid")
            ->groupBy('member_id')->get()->keyBy('member_id');

        $rows = $members->map(function (Member $m) use ($counts, $fines) {
            $byStatus = array_fill_keys(self::STATUSES, 0);
            foreach ($counts->get($m->id, collect()) as $c) {
                $byStatus[$c->status] = (int) $c->n;
            }
            $total = array_sum($byStatus);

            return [
                'member_id' => $m->id, 'member_no' => $m->member_no, 'name' => $m->name,
                'division' => $m->memberships->first()?->division?->name,
                'membership_status' => $m->memberships->first()?->status,
                ...$byStatus, 'total' => $total,
                'attendance_rate' => $total ? round(($byStatus['hadir'] + $byStatus['telat']) / $total * 100, 1) : null,
                'fines_count' => (int) ($fines[$m->id]->n ?? 0),
                'fines_unpaid' => (int) ($fines[$m->id]->unpaid ?? 0),
                'fines_paid' => (int) ($fines[$m->id]->paid ?? 0),
            ];
        })->values();

        $rated = $rows->whereNotNull('attendance_rate');

        return [
            'term' => $term->only('id', 'name', 'starts_on', 'ends_on'),
            'division_id' => $divisionId,
            'events' => Event::where('term_id', $term->id)->when($divisionId, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('division_id')->orWhere('division_id', $divisionId)))->count(),
            'average_rate' => $rated->isNotEmpty() ? round($rated->avg('attendance_rate'), 1) : null,
            // Kolom denda ditampilkan bila kebijakan aktif atau sudah ada denda di periode ini.
            'show_fines' => (bool) Organization::whereKey($term->organization_id)->value('fine_enabled') || $fines->isNotEmpty(),
            'fines_unpaid_total' => (int) $rows->sum('fines_unpaid'),
            'rows' => $rows,
        ];
    }

    /** Daftar sesi kegiatan beserta jendela check-in-nya (urut sesi utama dulu). */
    public function sessions(Event $event): array
    {
        return $event->sessions()->orderBy('id')->get()
            ->map->only('id', 'title', 'starts_at', 'ends_at', 'checkin_opens_at', 'late_after', 'checkin_closes_at')->values()->all();
    }

    private function summary($statuses): array
    {
        $counts = array_fill_keys([...self::STATUSES, 'belum_absen'], 0);
        foreach ($statuses as $s) {
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }
        $total = $statuses->count();

        return ['total' => $total, 'by_status' => $counts,
            'present_rate' => $total ? round(($counts['hadir'] + $counts['telat']) / $total * 100, 1) : null];
    }
}
