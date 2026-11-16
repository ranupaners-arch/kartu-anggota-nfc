<?php

namespace App\Services;

use App\Models\Division;
use App\Models\Event;
use App\Models\Member;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Kelola kegiatan. Dipakai API dan dashboard. */
class EventService
{
    public const WINDOW_KEYS = ['checkin_opens_at', 'late_after', 'checkin_closes_at'];

    public function __construct(private CloseEvent $closer) {}

    public function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'title' => "$req|string|max:200", 'type' => 'sometimes|string|max:50',
            'location' => 'nullable|string|max:200',
            'starts_at' => "$req|date", 'ends_at' => "$req|date|after:starts_at",
            'checkin_opens_at' => "$req|date", 'late_after' => "$req|date|after_or_equal:checkin_opens_at",
            'checkin_closes_at' => "$req|date|after_or_equal:late_after",
            'is_mandatory' => 'sometimes|boolean',
            'division_id' => 'nullable|integer',
            'target_division_ids' => 'array', 'target_division_ids.*' => 'integer',
            'target_member_ids' => 'array', 'target_member_ids.*' => 'integer',
        ];
    }

    /** Query kegiatan yang boleh dilihat user (Admin Divisi: divisinya + kegiatan umum). */
    public function visibleTo(User $user)
    {
        return Event::query()->when($user->role === User::DIVISION_ADMIN, fn ($q) => $q
            ->where(fn ($w) => $w->whereNull('division_id')->orWhere('division_id', $user->division_id)));
    }

    public function authorize(User $user, Event $event): void
    {
        abort_if($user->role === User::DIVISION_ADMIN && $event->division_id && $event->division_id !== $user->division_id,
            403, 'Bukan kegiatan divisi Anda.');
    }

    public function create(User $user, array $d): Event
    {
        $divisionIds = array_values(array_unique($d['target_division_ids'] ?? []));
        $memberIds = array_values(array_unique($d['target_member_ids'] ?? []));
        $ownerDivision = $user->role === User::DIVISION_ADMIN ? $user->division_id : ($d['division_id'] ?? null);
        $this->assertOwned($divisionIds, $memberIds, $ownerDivision);
        $term = Term::where('is_current', true)->firstOr(fn () => abort(422, 'Belum ada periode aktif.'));

        $event = DB::transaction(function () use ($d, $user, $term, $ownerDivision, $divisionIds, $memberIds) {
            $event = Event::create(collect($d)->except(['division_id', 'target_division_ids', 'target_member_ids'])->all() + [
                'term_id' => $term->id, 'division_id' => $ownerDivision, 'created_by' => $user->id,
            ]);
            $this->syncTargets($event, $divisionIds, $memberIds);
            $event->defaultSession();

            return $event;
        });
        AuditLogger::log('event.created', 'event', $event->id);

        return $event;
    }

    /** Jendela waktu kegiatan yang sudah berjalan hanya boleh diubah dengan alasan tercatat. */
    public function update(Event $event, array $d, ?string $reason = null): Event
    {
        $d = collect($d)->except(['division_id', 'target_division_ids', 'target_member_ids'])->all();
        $changesWindow = collect(self::WINDOW_KEYS)->contains(fn ($k) => isset($d[$k]));
        if ($changesWindow && $event->checkin_opens_at->lte(now())) {
            if (mb_strlen(trim((string) $reason)) < 5) {
                throw ValidationException::withMessages(['reason' => 'Kegiatan sudah berjalan: alasan perubahan jendela waktu wajib diisi (min. 5 karakter).']);
            }
            AuditLogger::log('event.window_changed', 'event', $event->id, [
                'reason' => $reason, 'before' => $event->only(self::WINDOW_KEYS), 'after' => array_intersect_key($d, array_flip(self::WINDOW_KEYS)),
            ]);
        }
        $event->update($d);

        return $event->fresh();
    }

    public function setTargets(Event $event, array $divisionIds, array $memberIds): void
    {
        $this->assertOwned($divisionIds, $memberIds);
        DB::transaction(fn () => $this->syncTargets($event, $divisionIds, $memberIds));
        AuditLogger::log('event.targets_changed', 'event', $event->id, ['divisions' => $divisionIds, 'members' => $memberIds]);
    }

    /** Tutup manual: tandai Alpa/Izin/Sakit untuk yang tidak scan. */
    public function close(Event $event): int
    {
        if ($event->closed_at) {
            throw ValidationException::withMessages(['event' => 'Kegiatan sudah ditutup.']);
        }

        return $this->closer->handle($event);
    }

    /** Hapus hanya bila belum ada absensi; selain itu tutup saja. */
    public function delete(Event $event): void
    {
        if ($event->attendances()->exists()) {
            throw ValidationException::withMessages(['event' => 'Kegiatan sudah memiliki absensi; tutup kegiatan, jangan dihapus.']);
        }
        $event->delete();
        AuditLogger::log('event.deleted', 'event', $event->id, ['title' => $event->title]);
    }

    private function syncTargets(Event $event, array $divisionIds, array $memberIds): void
    {
        $event->targets()->delete();
        foreach ($divisionIds as $id) {
            $event->targets()->create(['division_id' => $id]);
        }
        foreach ($memberIds as $id) {
            $event->targets()->create(['member_id' => $id]);
        }
    }

    private function assertOwned(array $divisionIds, array $memberIds, ?int $ownerDivision = null): void
    {
        $divs = array_values(array_unique(array_filter([...$divisionIds, $ownerDivision])));
        if (Division::whereIn('id', $divs)->count() !== count($divs)) {
            throw ValidationException::withMessages(['target_division_ids' => 'Divisi tidak dikenal.']);
        }
        if (Member::whereIn('id', $memberIds)->count() !== count($memberIds)) {
            throw ValidationException::withMessages(['target_member_ids' => 'Anggota tidak dikenal.']);
        }
    }
}
