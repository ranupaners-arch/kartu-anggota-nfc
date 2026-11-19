<?php

namespace App\Services;

use App\Mail\WeeklySummaryMail;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Ringkasan mingguan untuk pengurus: kegiatan 7 hari terakhir (% hadir), kegiatan 7 hari ke depan,
 * pekerjaan yang menunggu, dan anggota yang perlu perhatian — sesuai cakupan akun (admin divisi:
 * divisinya). Dihitung "sebagai" pengurus itu agar batas organisasi & divisi sama dengan dashboard.
 */
class WeeklySummaryService
{
    public function __construct(
        private EventService $events,
        private ExcuseService $excuses,
        private AttendanceService $attendances,
        private StatsService $stats,
    ) {}

    public function build(User $user): array
    {
        $from = now()->subDays(7);
        $closed = $this->events->visibleTo($user)->whereNotNull('closed_at')->whereBetween('starts_at', [$from, now()])
            ->orderBy('starts_at')->get(['id', 'title', 'starts_at']);
        $counts = Attendance::whereIn('event_id', $closed->pluck('id'))->whereNull('voided_at')
            ->selectRaw("event_id, count(*) as total, sum(case when status in ('hadir','telat') then 1 else 0 end) as present")
            ->groupBy('event_id')->get()->keyBy('event_id');
        $term = Term::where('is_current', true)->first();
        $atRisk = $term ? $this->stats->atRisk($user, $term) : [];

        return [
            'user' => $user->name,
            'organization' => $user->organization?->name ?? config('attendance.instance_name'),
            'from' => $from, 'to' => now(),
            'closed' => $closed->map(fn (Event $e) => [
                'title' => $e->title, 'date' => $e->starts_at,
                'present' => (int) ($counts[$e->id]->present ?? 0), 'total' => (int) ($counts[$e->id]->total ?? 0),
            ])->all(),
            'upcoming' => $this->events->visibleTo($user)->whereBetween('starts_at', [now(), now()->addDays(7)])
                ->orderBy('starts_at')->get(['title', 'starts_at', 'location'])->toArray(),
            'pendingExcuses' => $this->excuses->visibleTo($user)->where('status', 'pending')->count(),
            'needsReview' => $this->attendances->pendingReviewCount($user),
            'atRiskCount' => count($atRisk),
            'atRisk' => array_slice($atRisk, 0, 5),
            'dashboardUrl' => rtrim(url('/'), '/'),
        ];
    }

    /** Tidak ada apa pun untuk dilaporkan → tidak perlu mengirim email. */
    public function isEmpty(array $d): bool
    {
        return ! $d['closed'] && ! $d['upcoming'] && ! $d['pendingExcuses'] && ! $d['needsReview'] && ! $d['atRiskCount'];
    }

    /** Hitung sebagai pengurus itu (cakupan organisasi/divisi sama dengan saat ia login). */
    public function asUser(User $user, callable $fn): mixed
    {
        $previous = Auth::user();
        Auth::setUser($user);
        try {
            return $fn();
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }

    /** @return array{sent:int, skipped:int} */
    public function sendAll(): array
    {
        $sent = $skipped = 0;
        $recipients = User::withoutGlobalScopes()->whereIn('role', [User::SUPER_ADMIN, User::DIVISION_ADMIN])
            ->whereNull('disabled_at')->where('weekly_summary', true)->whereNotNull('organization_id')->get();
        foreach ($recipients as $user) {
            $data = $this->asUser($user, fn () => $this->build($user));
            if ($this->isEmpty($data)) {
                $skipped++;

                continue;
            }
            Mail::to($user->email)->send(new WeeklySummaryMail($data));
            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
