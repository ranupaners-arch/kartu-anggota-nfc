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

        $events = Event::where('term_id', $term->id)->where('ends_at', '>=', now()->subDays(30))
            ->orderBy('starts_at')->get()
            ->filter(fn (Event $e) => $e->targetMembers()->whereKey($memberId)->exists())->values();

        $attendance = Attendance::whereIn('event_id', $events->pluck('id'))->where('member_id', $memberId)->whereNull('voided_at')
            ->get()->keyBy('event_id');
        $excuses = ExcuseRequest::whereIn('event_id', $events->pluck('id'))->where('member_id', $memberId)->get()->keyBy('event_id');

        return ['data' => $events->map(function (Event $e) use ($attendance, $excuses) {
            $a = $attendance[$e->id] ?? null;
            $x = $excuses[$e->id] ?? null;
            $present = $a && in_array($a->status, ['hadir', 'telat'], true);

            return $e->only('id', 'title', 'type', 'location', 'starts_at', 'ends_at', 'checkin_opens_at', 'late_after', 'checkin_closes_at', 'closed_at', 'is_mandatory') + [
                'my_status' => $a?->status,
                'excuse' => $x?->only('id', 'type', 'reason', 'status', 'review_note'),
                'can_request_excuse' => ! $e->closed_at && ! $present && (! $x || $x->status === 'pending'),
            ];
        })];
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
            'recent' => $rows->take(50)->map(fn (Attendance $a) => [
                'event' => $a->event?->title, 'date' => $a->event?->starts_at, 'status' => $a->status, 'source' => $a->source,
            ]),
        ];
    }

    private function memberId(Request $r): int
    {
        return $r->user()->member_id ?? abort(422, 'Akun belum terhubung ke data anggota. Hubungi pengurus.');
    }
}
