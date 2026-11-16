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

        return view('dashboard.home', [
            'term' => $term,
            'activeMembers' => $term ? Membership::where('term_id', $term->id)->where('status', 'active')
                ->when($user->role === 'division_admin', fn ($q) => $q->where('division_id', $user->division_id))
                ->whereHas('member')->count() : 0,
            'ongoing' => $events->visibleTo($user)->whereNull('closed_at')
                ->where('checkin_opens_at', '<=', now())->where('checkin_closes_at', '>=', now())
                ->withCount(['attendances as present_count' => fn ($q) => $q->whereNull('voided_at')->whereIn('status', ['hadir', 'telat'])])
                ->orderBy('starts_at')->get(),
            'upcoming' => $events->visibleTo($user)->where('checkin_opens_at', '>', now())->orderBy('starts_at')->limit(5)->get(),
            'pendingExcuses' => $excuses->visibleTo($user)->where('status', 'pending')->count(),
            'needsReview' => $attendances->pendingReviewCount($user),
            'scans7d' => $attendances->visibleTo($user)->where('scanned_at', '>=', now()->subDays(7))->count(),
        ]);
    }
}
