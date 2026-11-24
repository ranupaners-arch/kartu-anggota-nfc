<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Term;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Papan peringkat poin periode berjalan. Daftar hanya memuat anggota yang memilih tampil, dengan nomor
 * urut di antara mereka saja; peringkat pribadi (di antara semua anggota aktif) hanya untuk pemiliknya.
 */
class LeaderboardService
{
    public const TOP = 20;

    public function forMember(Member $member): array
    {
        $term = Term::where('is_current', true)->first();
        $out = ['term' => $term?->name, 'opted_in' => $member->leaderboard_opt_in_at !== null, 'me' => null, 'top' => []];
        $active = $term ? $this->activeMembers($term) : collect();
        if (! $active->has($member->id)) {
            return $out; // pendaftar/alumni: tidak ikut & tidak melihat
        }

        $points = $this->points($term);
        $of = fn (int $id) => (int) ($points[$id] ?? 0);
        $mine = $of($member->id);
        $out['me'] = ['points' => $mine, 'rank' => $active->keys()->filter(fn ($id) => $of($id) > $mine)->count() + 1, 'total' => $active->count()];

        $shown = $active->filter(fn (Member $m) => $m->leaderboard_opt_in_at && $of($m->id) > 0)
            ->sortBy([fn (Member $a, Member $b) => $of($b->id) <=> $of($a->id), fn (Member $a, Member $b) => $a->name <=> $b->name])->values();
        $out['top'] = $shown->take(self::TOP)->map(fn (Member $m) => [
            'rank' => $shown->filter(fn (Member $x) => $of($x->id) > $of($m->id))->count() + 1, // poin sama = peringkat sama
            'name' => $m->name, 'division' => $m->memberships->first()?->division?->name,
            'points' => $of($m->id), 'is_me' => $m->id === $member->id,
        ])->all();

        return $out;
    }

    public function setOptIn(Member $member, bool $optIn): void
    {
        $member->update(['leaderboard_opt_in_at' => $optIn ? ($member->leaderboard_opt_in_at ?? now()) : null]);
        AuditLogger::log($optIn ? 'leaderboard.opt_in' : 'leaderboard.opt_out', 'member', $member->id);
    }

    /** @return Collection<int, Member> id → anggota aktif periode ini */
    private function activeMembers(Term $term): Collection
    {
        return Member::whereHas('memberships', fn ($q) => $q->where('term_id', $term->id)->where('status', 'active'))
            ->with(['memberships' => fn ($q) => $q->where('term_id', $term->id), 'memberships.division:id,name'])
            ->get(['id', 'name', 'organization_id', 'leaderboard_opt_in_at'])->keyBy('id');
    }

    /**
     * Poin per anggota, sama dengan AchievementService: satu nilai terbaik per kegiatan.
     *
     * @return Collection<int, int>
     */
    private function points(Term $term): Collection
    {
        $case = collect(AchievementService::POINTS)->map(fn (int $p, string $s) => "when '$s' then $p")->implode(' ');
        $best = DB::table('attendances')->join('events', 'events.id', '=', 'attendances.event_id')
            ->where('events.term_id', $term->id)->whereNull('attendances.voided_at')
            ->groupBy('attendances.member_id', 'attendances.event_id')
            ->selectRaw("attendances.member_id, max(case attendances.status $case else 0 end) as best");

        return DB::query()->fromSub($best, 'b')->groupBy('member_id')->selectRaw('member_id, sum(best) as points')->pluck('points', 'member_id');
    }
}
