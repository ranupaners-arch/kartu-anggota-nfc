<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Term;
use App\Services\AttendanceService;
use App\Services\EventService;
use App\Services\ExcuseService;
use App\Services\StatsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function __invoke(Request $r, EventService $events, ExcuseService $excuses, AttendanceService $attendances)
    {
        $user = $r->user();
        $term = Term::where('is_current', true)->first();

        return Inertia::render('home', [
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
                'trend' => app(StatsService::class)->trend($user, $term),
                'divisions' => app(StatsService::class)->divisions($user, $term),
                'comparison' => app(StatsService::class)->comparison($user, $term),
                'atRisk' => array_slice(app(StatsService::class)->atRisk($user, $term), 0, 20),
                'rules' => ['streak' => StatsService::ALPA_STREAK, 'minRate' => StatsService::MIN_RATE, 'minRecords' => StatsService::MIN_RECORDS],
            ] : null,
        ]);
    }
}
