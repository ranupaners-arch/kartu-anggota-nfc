<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRsvp;
use App\Models\Member;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RSVP & kuota. Semua perubahan mengunci baris kegiatan, jadi kuota tidak terlampaui oleh jawaban bersamaan.
 * RSVP hanya perkiraan peserta: scan & penandaan Alpa tidak berubah.
 */
class RsvpService
{
    public function isOpen(Event $event): bool
    {
        return $event->rsvp_enabled && ! $event->closed_at && now()->lt($event->rsvp_deadline ?? $event->starts_at);
    }

    /** Jawab RSVP. Kuota penuh → daftar tunggu; batal → antrean terdepan naik. */
    public function respond(Event $event, int $memberId, bool $going): EventRsvp
    {
        return DB::transaction(function () use ($event, $memberId, $going) {
            $event = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_unless($event->targetMembers()->whereKey($memberId)->exists(), 403, 'Anda bukan peserta kegiatan ini.');
            abort_unless($this->isOpen($event), 422, 'RSVP kegiatan ini tidak dibuka atau sudah ditutup.');

            $rsvp = EventRsvp::where('event_id', $event->id)->where('member_id', $memberId)->first();
            $was = $rsvp?->status;
            if ($going && in_array($was, [EventRsvp::GOING, EventRsvp::WAITLIST], true)) {
                return $rsvp; // sudah menjawab: posisi antrean tidak berubah
            }
            if ($going) {
                $this->promote($event); // yang menunggu lebih dulu didahulukan
            }
            // Baris baru tiap jawaban: urutan antrean = id (waktu bisa sama dalam satu detik).
            $rsvp?->delete();
            $rsvp = EventRsvp::create([
                'event_id' => $event->id, 'member_id' => $memberId, 'responded_at' => now(),
                'status' => ! $going ? EventRsvp::DECLINED : ($this->hasRoom($event) ? EventRsvp::GOING : EventRsvp::WAITLIST),
            ]);
            if ($was === EventRsvp::GOING) {
                $this->promote($event);
            }

            return $rsvp;
        });
    }

    /** Pengaturan atau target kegiatan berubah: isi tempat kosong dari daftar tunggu. */
    public function refill(Event $event): void
    {
        DB::transaction(function () use ($event) {
            $event = Event::whereKey($event->id)->lockForUpdate()->first();
            if ($event && $event->rsvp_enabled && ! $event->closed_at && $event->starts_at->gt(now())) {
                $this->promote($event);
            }
        });
    }

    private function hasRoom(Event $event): bool
    {
        return $event->quota === null || $this->goingCount($event) < $event->quota;
    }

    /** Yang tidak lagi menjadi target (pindah divisi, alumni) tidak memakan tempat. */
    private function goingCount(Event $event): int
    {
        return EventRsvp::where('event_id', $event->id)->where('status', EventRsvp::GOING)
            ->whereIn('member_id', $event->targetMembers()->select('members.id'))->count();
    }

    private function promote(Event $event): void
    {
        $waiting = EventRsvp::where('event_id', $event->id)->where('status', EventRsvp::WAITLIST)
            ->whereIn('member_id', $event->targetMembers()->select('members.id'))
            ->orderBy('id');
        if ($event->quota !== null) {
            $waiting->limit(max(0, $event->quota - $this->goingCount($event)));
        }
        foreach ($waiting->get() as $r) {
            $r->update(['status' => EventRsvp::GOING]);
            Notifier::later('rsvpPromoted', $r->id);
        }
    }

    /**
     * Ringkasan untuk anggota per kegiatan (hanya kegiatan ber-RSVP).
     *
     * @param  Collection<int, Event>  $events
     * @return array<int, array{status: ?string, open: bool, deadline: ?string, quota: ?int, going: int, position: ?int}>
     */
    public function forMember(Collection $events, int $memberId): array
    {
        $out = [];
        foreach ($events->where('rsvp_enabled', true) as $e) {
            $mine = EventRsvp::where('event_id', $e->id)->where('member_id', $memberId)->first();
            $out[$e->id] = [
                'status' => $mine?->status,
                'open' => $this->isOpen($e),
                'deadline' => ($e->rsvp_deadline ?? $e->starts_at)->toIso8601String(),
                'quota' => $e->quota,
                'going' => $this->goingCount($e),
                'position' => $mine?->status === EventRsvp::WAITLIST
                    ? EventRsvp::where('event_id', $e->id)->where('status', EventRsvp::WAITLIST)
                        ->whereIn('member_id', $e->targetMembers()->select('members.id'))
                        ->where('id', '<=', $mine->id)->count()
                    : null,
            ];
        }

        return $out;
    }

    /** Rekap untuk dashboard: jumlah per jawaban & daftar semua peserta (belum menjawab = null). */
    public function report(Event $event): array
    {
        $answers = EventRsvp::where('event_id', $event->id)->get()->keyBy('member_id');
        $order = [EventRsvp::GOING => 0, EventRsvp::WAITLIST => 1, EventRsvp::DECLINED => 2];
        $rows = $event->targetMembers()->orderBy('name')->get(['members.id', 'member_no', 'name'])
            ->map(fn (Member $m) => ['member_id' => $m->id, 'member_no' => $m->member_no, 'name' => $m->name,
                'status' => $answers[$m->id]->status ?? null, 'responded_at' => ($answers[$m->id] ?? null)?->responded_at?->toIso8601String()])
            ->sortBy(fn ($r) => [$order[$r['status']] ?? 3, $r['status'] === EventRsvp::WAITLIST ? $answers[$r['member_id']]->id : 0])->values();
        $by = $rows->countBy(fn ($r) => $r['status'] ?? 'none');

        return [
            'counts' => ['going' => $by['going'] ?? 0, 'waitlist' => $by['waitlist'] ?? 0, 'declined' => $by['declined'] ?? 0, 'none' => $by['none'] ?? 0],
            'rows' => $rows,
        ];
    }
}
