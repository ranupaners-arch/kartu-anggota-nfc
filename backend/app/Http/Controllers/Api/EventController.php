<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Division;
use App\Models\Member;
use App\Models\Event;
use App\Models\Term;
use App\Services\AuditLogger;
use App\Support\CardPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EventController extends Controller
{
    public function index(Request $r)
    {
        $q = Event::query()->orderByDesc('starts_at');
        $u = $r->user();
        if ($u->role === 'division_admin') {
            $q->where(fn ($w) => $w->whereNull('division_id')->orWhere('division_id', $u->division_id));
        }
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
        $d = $this->validated($r);
        $u = $r->user();
        $r->validate([
            'division_id' => 'nullable|integer',
            'target_division_ids' => 'array', 'target_division_ids.*' => 'integer',
            'target_member_ids' => 'array', 'target_member_ids.*' => 'integer',
        ]);
        // Semua ID harus milik organisasi user (query ter-scope).
        $divisionIds = array_unique(array_filter([...$r->input('target_division_ids', []), $r->input('division_id')]));
        abort_if(Division::whereIn('id', $divisionIds)->count() !== count($divisionIds), 422, 'Divisi tidak dikenal.');
        $memberIds = array_unique($r->input('target_member_ids', []));
        abort_if(Member::whereIn('id', $memberIds)->count() !== count($memberIds), 422, 'Anggota tidak dikenal.');
        $term = Term::where('organization_id', $u->organization_id)->where('is_current', true)->firstOrFail();

        $event = Event::create($d + [
            'organization_id' => $u->organization_id, 'term_id' => $term->id,
            'division_id' => $u->role === 'division_admin' ? $u->division_id : ($r->input('division_id')),
            'created_by' => $u->id,
        ]);
        foreach ($r->input('target_division_ids', []) as $id) {
            $event->targets()->create(['division_id' => $id]);
        }
        foreach ($r->input('target_member_ids', []) as $id) {
            $event->targets()->create(['member_id' => $id]);
        }
        $event->defaultSession();
        AuditLogger::log('event.created', 'event', $event->id);

        return response()->json($event, 201);
    }

    public function update(Request $r, Event $event)
    {
        $d = $this->validated($r, partial: true);
        $windowKeys = ['checkin_opens_at', 'late_after', 'checkin_closes_at'];
        $changesWindow = collect($windowKeys)->contains(fn ($k) => isset($d[$k]));

        // Jendela waktu kegiatan yang sudah berjalan hanya boleh diubah dengan alasan tercatat.
        if ($changesWindow && $event->checkin_opens_at->lte(now())) {
            $r->validate(['reason' => 'required|string|min:5']);
            AuditLogger::log('event.window_changed', 'event', $event->id, [
                'reason' => $r->input('reason'), 'before' => $event->only($windowKeys), 'after' => $d,
            ]);
        }
        $event->update($d);

        return $event->fresh();
    }

    /** Data cache offline untuk app petugas. */
    public function roster(Event $event)
    {
        $members = $event->targetMembers()->with(['memberships.division', 'cards'])->get()
            ->map(fn ($m) => [
                'id' => $m->id, 'member_no' => $m->member_no, 'name' => $m->name,
                'division' => $m->memberships->sortByDesc('term_id')->first()?->division?->name,
                'photo_url' => $m->photo_path ? Storage::url($m->photo_path) : null,
                'cards' => $m->cards->map(fn (Card $c) => [
                    'public_id' => $c->public_id, 'status' => $c->status,
                    // sha256 payload: verifikasi offline tanpa secret HMAC di perangkat
                    'payload_sha256' => CardPayload::fingerprint(CardPayload::make($c)),
                ])->values(),
            ]);

        return ['event' => $event, 'members' => $members, 'generated_at' => now()->toIso8601String()];
    }

    private function validated(Request $r, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $r->validate([
            'title' => "$req|string|max:200", 'type' => 'sometimes|string|max:50',
            'location' => 'nullable|string|max:200',
            'starts_at' => "$req|date", 'ends_at' => "$req|date|after:starts_at",
            'checkin_opens_at' => "$req|date", 'late_after' => "$req|date|after_or_equal:checkin_opens_at",
            'checkin_closes_at' => "$req|date|after_or_equal:late_after",
            'is_mandatory' => 'sometimes|boolean',
        ]);
    }
}
