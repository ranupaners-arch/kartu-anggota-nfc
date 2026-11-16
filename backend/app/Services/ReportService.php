<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\Term;

/** Rekap absensi. Semua angka mengabaikan absensi yang di-void. */
class ReportService
{
    public const STATUSES = ['hadir', 'telat', 'izin', 'sakit', 'alpa'];

    /** Rekap per kegiatan: jumlah per status, persentase, daftar hadir. */
    public function event(Event $event): array
    {
        $session = $event->defaultSession();
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
                'member_id' => $m->id, 'member_no' => $m->member_no, 'name' => $m->name,
                'division' => $m->memberships->first()?->division?->name,
                'status' => $a?->status ?? 'belum_absen',
                'scanned_at' => $a?->scanned_at?->toIso8601String(), 'source' => $a?->source,
                'needs_review' => (bool) $a?->needs_review,
            ];
        })->values();

        return [
            'event' => $event->only('id', 'title', 'type', 'location', 'starts_at', 'closed_at'),
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

        $rows = $members->map(function (Member $m) use ($counts) {
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
            ];
        })->values();

        $rated = $rows->whereNotNull('attendance_rate');

        return [
            'term' => $term->only('id', 'name', 'starts_on', 'ends_on'),
            'division_id' => $divisionId,
            'events' => Event::where('term_id', $term->id)->when($divisionId, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('division_id')->orWhere('division_id', $divisionId)))->count(),
            'average_rate' => $rated->isNotEmpty() ? round($rated->avg('attendance_rate'), 1) : null,
            'rows' => $rows,
        ];
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
