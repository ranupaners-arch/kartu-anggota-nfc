<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Member;
use App\Services\EventMonitorService;
use App\Services\EventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/** Monitor kegiatan langsung (layar besar panitia); data diperbarui lewat polling partial reload. */
class EventMonitorController extends Controller
{
    public function __construct(private EventService $events, private EventMonitorService $monitor) {}

    public function show(Request $r, Event $event)
    {
        $this->events->authorize($r->user(), $event);
        // Hitung sekali per request, lalu bagikan ke prop yang diminta (partial reload hanya meminta sebagian).
        // ?sesi= memilih sesi; tanpa itu monitor mengikuti sesi yang sedang membuka check-in.
        $sessionId = $r->integer('sesi') ?: null;
        $snapshot = null;
        $snap = function () use (&$snapshot, $event, $sessionId) {
            $event = $event->fresh();

            return $snapshot ??= $this->monitor->snapshot($event, $this->monitor->sessionFor($event, $sessionId));
        };

        return Inertia::render('events/monitor', [
            'event' => $event->only('id', 'title', 'location', 'starts_at', 'ends_at', 'checkin_opens_at', 'late_after', 'checkin_closes_at', 'closed_at'),
            'stats' => fn () => $snap()['stats'],
            'latest' => fn () => $snap()['latest'],
            'missing' => fn () => $snap()['missing'],
            'serverTime' => fn () => $snap()['serverTime'],
            'arrivals' => fn () => $snap()['arrivals'],
            'divisions' => fn () => $snap()['divisions'],
            'session' => fn () => $snap()['session'],
            'sessions' => fn () => $snap()['sessions'],
            'pinnedSession' => $sessionId,
        ]);
    }

    /** Foto anggota untuk monitor: hanya peserta kegiatan ini, hanya bagi yang boleh melihat kegiatannya. */
    public function photo(Request $r, Event $event, Member $member)
    {
        $this->events->authorize($r->user(), $event);
        abort_unless($this->monitor->memberBelongs($event, $member->id), 404);
        abort_unless($member->photo_path && Storage::disk('local')->exists($member->photo_path), 404);

        return Storage::disk('local')->response($member->photo_path, headers: ['Cache-Control' => 'private, max-age=3600']);
    }
}
