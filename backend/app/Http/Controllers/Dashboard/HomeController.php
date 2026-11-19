<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Term;
use App\Services\AttendanceService;
use App\Services\EventService;
use App\Services\ExcuseService;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(Request $r, EventService $events, ExcuseService $excuses, AttendanceService $attendances)
    {
        $user = $r->user();
        $term = Term::where('is_current', true)->first();

        return \Inertia\Inertia::render('home', [
            'term' => $term?->only('id', 'name'),
            'activeMembers' => $term ? Membership::where('term_id', $term->id)->where('status', 'active')
                ->when($user->role === 'division_admin', fn ($q) => $q->where('division_id', $user->division_id))
                ->whereHas('member')->count() : 0,
            'ongoing' => $events->visibleTo($user)->ongoing()->select('events.*')->withPresentCount()->orderBy('starts_at')->get(),
            'upcoming' => $events->visibleTo($user)->where('checkin_opens_at', '>', now())->orderBy('starts_at')->limit(5)->get(),
            'pendingExcuses' => $excuses->visibleTo($user)->where('status', 'pending')->count(),
            'needsReview' => $attendances->pendingReviewCount($user),
            'scans7d' => $attendances->visibleTo($user)->where('scanned_at', '>=', now()->subDays(7))->count(),
            // Statistik & peringatan (dihitung hanya bila ada periode berjalan).
            'stats' => fn () => $term ? [
                'trend' => app(\App\Services\StatsService::class)->trend($user, $term),
                'divisions' => app(\App\Services\StatsService::class)->divisions($user, $term),
                'comparison' => app(\App\Services\StatsService::class)->comparison($user, $term),
                'atRisk' => array_slice(app(\App\Services\StatsService::class)->atRisk($user, $term), 0, 20),
                'rules' => ['streak' => \App\Services\StatsService::ALPA_STREAK, 'minRate' => \App\Services\StatsService::MIN_RATE, 'minRecords' => \App\Services\StatsService::MIN_RECORDS],
            ] : null,
        ]);
    }
}
