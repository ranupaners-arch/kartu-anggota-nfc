<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Event;
use App\Services\EventService;
use App\Support\CardPayload;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(private EventService $events) {}

    public function index(Request $r)
    {
        $q = $this->events->visibleTo($r->user())->orderByDesc('starts_at');
        match ($r->query('status')) {
            'ongoing' => $q->whereNull('closed_at')->where('checkin_opens_at', '<=', now())->where('checkin_closes_at', '>=', now()),
            'upcoming' => $q->where('checkin_opens_at', '>', now()),
            'closed' => $q->whereNotNull('closed_at'),
            default => null,
        };

        return $q->paginate(30);
    }

    public function store(Request $r)
    {
        $event = $this->events->create($r->user(), $r->validate($this->events->rules()));

        return response()->json($event, 201);
    }

    public function update(Request $r, Event $event)
    {
        $this->events->authorize($r->user(), $event);

        return $this->events->update($event, $r->validate($this->events->rules(partial: true)), $r->input('reason'));
    }

    /** Data cache offline untuk app petugas. */
    public function roster(Event $event)
    {
        $members = $event->targetMembers()->with(['memberships.division', 'cards'])->get()
            ->map(fn ($m) => [
                'id' => $m->id, 'member_no' => $m->member_no, 'name' => $m->name,
                'division' => $m->memberships->sortByDesc('term_id')->first()?->division?->name,
                'photo_url' => $m->photo_path ? route('api.members.photo', $m) : null,
                'cards' => $m->cards->map(fn (Card $c) => [
                    'public_id' => $c->public_id, 'status' => $c->status,
                    // sha256 payload: verifikasi offline tanpa secret HMAC di perangkat
                    'payload_sha256' => CardPayload::fingerprint(CardPayload::make($c)),
                ])->values(),
            ]);

        return ['event' => $event, 'members' => $members, 'generated_at' => now()->toIso8601String()];
    }
}
