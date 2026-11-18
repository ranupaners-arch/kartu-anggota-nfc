<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventSession;

/** Potret kondisi kegiatan saat ini untuk layar monitor langsung. */
class EventMonitorService
{
    public const LATEST_LIMIT = 15;
    public const MISSING_LIMIT = 300;

    public function __construct(private ReportService $reports) {}

    /** Sesi yang dipantau: pilihan, atau yang sedang membuka check-in, atau sesi utama. */
    public function sessionFor(Event $event, ?int $sessionId = null): EventSession
    {
        return $sessionId ? $event->sessions()->findOrFail($sessionId) : ($event->currentSession() ?? $event->defaultSession());
    }

    public function snapshot(Event $event, ?EventSession $session = null): array
    {
        $session ??= $this->sessionFor($event);
        $report = $this->reports->event($event, $session);
        $by = $report['summary']['by_status'];
        $target = $report['summary']['total'];
        $present = $by['hadir'] + $by['telat'];

        $rows = collect($report['rows']);
        $divisionOf = $rows->pluck('division', 'member_id');
        $missing = $rows->where('status', 'belum_absen')->sortBy('name')->values();

        $latest = Attendance::query()
            ->with('member:id,member_no,name,photo_path')
            ->where('session_id', $session->id)->whereNull('voided_at')->whereNotNull('scanned_at')
            ->latest('scanned_at')->latest('id')->limit(self::LATEST_LIMIT)->get()
            ->map(fn (Attendance $a) => [
                'id' => $a->id,
                'status' => $a->status,
                'source' => $a->source,
                'scanned_at' => $a->scanned_at,
                'needs_review' => $a->needs_review,
                'member' => [
                    'name' => $a->member?->name,
                    'member_no' => $a->member?->member_no,
                    'division' => $divisionOf[$a->member_id] ?? null,
                    'photo_url' => $a->member?->photoUrl('events.monitor.photo', [$event, $a->member_id]),
                ],
            ]);

        return [
            'stats' => [
                'target' => $target,
                'present' => $present,
                'hadir' => $by['hadir'],
                'telat' => $by['telat'],
                'excused' => $by['izin'] + $by['sakit'],
                'alpa' => $by['alpa'],
                'missing' => $by['belum_absen'],
                'rate' => $target ? round($present / $target * 100, 1) : null,
            ],
            'latest' => $latest,
            'missing' => [
                'total' => $missing->count(),
                'people' => $missing->take(self::MISSING_LIMIT)->map(fn ($r) => [
                    'member_id' => $r['member_id'], 'member_no' => $r['member_no'], 'name' => $r['name'], 'division' => $r['division'],
                ])->values(),
            ],
            'session' => $session->only('id', 'title', 'checkin_opens_at', 'late_after', 'checkin_closes_at'),
            'sessions' => count($report['sessions']) > 1 ? collect($report['sessions'])->map(fn ($x) => ['id' => $x['id'], 'title' => $x['title']])->all() : [],
            'serverTime' => now()->toIso8601String(),
        ];
    }

    /** Anggota boleh tampil fotonya di monitor kegiatan ini bila ia peserta atau sudah tercatat absen. */
    public function memberBelongs(Event $event, int $memberId): bool
    {
        return $event->targetMembers()->whereKey($memberId)->exists()
            || Attendance::where('event_id', $event->id)->where('member_id', $memberId)->exists();
    }
}
