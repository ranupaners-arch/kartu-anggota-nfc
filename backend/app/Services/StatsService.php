<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Division;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Statistik kehadiran periode berjalan & peringatan anggota yang perlu perhatian.
 * Admin Divisi hanya melihat divisinya (kegiatan: divisinya + umum).
 */
class StatsService
{
    /** Alpa berturut-turut (dari yang terbaru) yang memicu peringatan. */
    public const ALPA_STREAK = 3;

    /** Persentase hadir minimum; di bawahnya diperingatkan bila sudah cukup data. */
    public const MIN_RATE = 60;

    public const MIN_RECORDS = 5;

    public function __construct(private EventService $events) {}

    /** Persentase hadir (hadir+telat) per kegiatan yang sudah ditutup, 12 terakhir, urut waktu. */
    public function trend(User $user, Term $term, int $limit = 12): array
    {
        $events = $this->events->visibleTo($user)->where('term_id', $term->id)->whereNotNull('closed_at')
            ->orderByDesc('starts_at')->limit($limit)->get(['id', 'title', 'starts_at'])->reverse()->values();
        $counts = Attendance::whereIn('event_id', $events->pluck('id'))->whereNull('voided_at')
            ->selectRaw("event_id, count(*) as total, sum(case when status in ('hadir','telat') then 1 else 0 end) as present")
            ->groupBy('event_id')->get()->keyBy('event_id');

        return $events->map(function (Event $e) use ($counts) {
            $c = $counts[$e->id] ?? null;

            return [
                'id' => $e->id, 'title' => $e->title, 'date' => $e->starts_at,
                'total' => (int) ($c->total ?? 0), 'present' => (int) ($c->present ?? 0),
                'rate' => $c && $c->total ? round($c->present / $c->total * 100, 1) : null,
            ];
        })->filter(fn ($r) => $r['total'] > 0)->values()->all();
    }

    /** Persentase hadir per divisi (keanggotaan periode berjalan). */
    public function divisions(User $user, Term $term): array
    {
        $rows = $this->divisionRows($user, $term);
        $names = Division::whereIn('id', array_column($rows, 'division_id'))->pluck('name', 'id');

        return collect($rows)->map(fn ($r) => [
            'division' => $names[$r['division_id']] ?? '-', 'total' => $r['total'], 'present' => $r['present'],
            'rate' => $r['total'] ? round($r['present'] / $r['total'] * 100, 1) : null,
        ])->sortBy('rate')->values()->all();
    }

    /** Hadir & total per divisi (menurut keanggotaan di periode itu). */
    private function divisionRows(User $user, Term $term): array
    {
        return Attendance::query()->whereNull('voided_at')
            ->join('events', 'events.id', '=', 'attendances.event_id')->where('events.term_id', $term->id)
            ->join('memberships', fn ($j) => $j->on('memberships.member_id', '=', 'attendances.member_id')->where('memberships.term_id', $term->id))
            ->when($user->role === User::DIVISION_ADMIN, fn ($q) => $q->where('memberships.division_id', $user->division_id))
            ->whereNotNull('memberships.division_id')
            ->selectRaw("memberships.division_id, count(*) as total, sum(case when attendances.status in ('hadir','telat') then 1 else 0 end) as present")
            ->groupBy('memberships.division_id')->get()
            ->map(fn ($r) => ['division_id' => $r->division_id, 'total' => (int) $r->total, 'present' => (int) $r->present])->all();
    }

    /**
     * Bandingkan periode ini dengan periode sebelumnya (periode terakhir yang mulai lebih awal):
     * % hadir keseluruhan dan per divisi. null bila belum ada periode sebelumnya.
     */
    public function comparison(User $user, Term $term): ?array
    {
        $previous = Term::where('starts_on', '<', $term->starts_on)->orderByDesc('starts_on')->first();
        if (! $previous) {
            return null;
        }
        $now = collect($this->divisionRows($user, $term))->keyBy('division_id');
        $before = collect($this->divisionRows($user, $previous))->keyBy('division_id');
        $names = Division::whereIn('id', $now->keys()->merge($before->keys()))->pluck('name', 'id');
        $rate = fn ($r) => $r && $r['total'] ? round($r['present'] / $r['total'] * 100, 1) : null;

        return [
            'current' => ['name' => $term->name] + $this->overall($user, $term),
            'previous' => ['name' => $previous->name] + $this->overall($user, $previous),
            'divisions' => $names->map(fn ($name, $id) => [
                'division' => $name, 'current' => $rate($now->get($id)), 'previous' => $rate($before->get($id)),
            ])->sortBy('division')->values()->all(),
        ];
    }

    /** Hadir/total seluruh catatan kehadiran periode (admin divisi: anggota divisinya saja). */
    private function overall(User $user, Term $term): array
    {
        $r = Attendance::query()->whereNull('voided_at')
            ->join('events', 'events.id', '=', 'attendances.event_id')->where('events.term_id', $term->id)
            ->when($user->role === User::DIVISION_ADMIN, fn ($q) => $q->whereExists(fn ($e) => $e->from('memberships')
                ->whereColumn('memberships.member_id', 'attendances.member_id')->where('memberships.term_id', $term->id)
                ->where('memberships.division_id', $user->division_id)))
            ->selectRaw("count(*) as total, sum(case when attendances.status in ('hadir','telat') then 1 else 0 end) as present")->first();
        $total = (int) $r->total;

        return ['total' => $total, 'present' => (int) $r->present, 'rate' => $total ? round($r->present / $total * 100, 1) : null];
    }

    /** Anggota aktif yang perlu perhatian: Alpa berturut-turut, atau persentase hadir rendah. */
    public function atRisk(User $user, Term $term): array
    {
        $members = Membership::with(['member:id,member_no,name', 'division:id,name'])->where('term_id', $term->id)->where('status', 'active')
            ->when($user->role === User::DIVISION_ADMIN, fn ($q) => $q->where('division_id', $user->division_id))->get()
            ->filter(fn ($m) => $m->member)->keyBy('member_id');
        $history = $this->history($term, $members->keys());

        return $members->map(function (Membership $m) use ($history) {
            $r = $this->risk($history->get($m->member_id, collect()));

            return $r['at_risk'] ? $r + ['member_id' => $m->member_id, 'member_no' => $m->member->member_no, 'name' => $m->member->name, 'division' => $m->division?->name] : null;
        })->filter()->sortByDesc(fn ($r) => [$r['alpa_streak'], -($r['rate'] ?? 100)])->values()->all();
    }

    /** Ringkasan risiko satu anggota (dipakai app anggota). */
    public function memberRisk(int $memberId, Term $term): array
    {
        return $this->risk($this->history($term, collect([$memberId]))->get($memberId, collect()));
    }

    /** Status absensi per anggota, urut waktu kegiatan & sesi (lama → baru). */
    private function history(Term $term, Collection $memberIds): Collection
    {
        return Attendance::query()->whereNull('voided_at')->whereIn('attendances.member_id', $memberIds)
            ->join('events', 'events.id', '=', 'attendances.event_id')->where('events.term_id', $term->id)
            ->orderBy('events.starts_at')->orderBy('attendances.session_id')
            ->get(['attendances.member_id', 'attendances.status'])->groupBy('member_id')->map->pluck('status');
    }

    private function risk(Collection $statuses): array
    {
        $streak = 0;
        foreach ($statuses->reverse() as $s) {
            if ($s !== Attendance::ALPA) {
                break;
            }
            $streak++;
        }
        $total = $statuses->count();
        $present = $statuses->filter(fn ($s) => in_array($s, ['hadir', 'telat'], true))->count();
        $rate = $total ? round($present / $total * 100, 1) : null;

        return [
            'alpa_streak' => $streak, 'rate' => $rate, 'total' => $total,
            'at_risk' => $streak >= self::ALPA_STREAK || ($total >= self::MIN_RECORDS && $rate < self::MIN_RATE),
        ];
    }
}
