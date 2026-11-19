<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Division;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Member;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    public const REPEATS = ['none', 'weekly', 'biweekly', 'monthly'];
    public const MAX_REPEAT = 12;

    /**
     * Duplikat kegiatan ke jam mulai baru (opsional berulang): judul, tipe, lokasi, wajib hadir,
     * divisi pemilik, target peserta, dan sesi tambahan ikut disalin; semua waktu bergeser dengan
     * selisih yang sama terhadap jam mulai. Masuk periode berjalan.
     *
     * @return Collection<int, Event>
     */
    public function duplicate(User $user, Event $source, Carbon $startsAt, string $repeat = 'none', int $count = 1): Collection
    {
        $this->authorize($user, $source);
        abort_unless(in_array($repeat, self::REPEATS, true), 422, 'Pola ulang tidak dikenal.');
        $count = $repeat === 'none' ? 1 : max(1, min(self::MAX_REPEAT, $count));
        $term = Term::where('is_current', true)->firstOr(fn () => abort(422, 'Belum ada periode aktif.'));
        $source->load(['targets', 'sessions']);
        $extraSessions = $source->sessions->sortBy('id')->slice(1);

        $created = DB::transaction(function () use ($user, $source, $startsAt, $repeat, $count, $term, $extraSessions) {
            $out = collect();
            for ($i = 0; $i < $count; $i++) {
                $start = match ($repeat) {
                    'weekly' => $startsAt->copy()->addWeeks($i),
                    'biweekly' => $startsAt->copy()->addWeeks(2 * $i),
                    'monthly' => $startsAt->copy()->addMonthsNoOverflow($i), // 31 Jan → 28/29 Feb, bukan 3 Mar
                    default => $startsAt->copy(),
                };
                $shift = fn (?Carbon $t) => $t ? $start->copy()->addSeconds((int) $source->starts_at->diffInSeconds($t, false)) : null;
                $event = Event::create([
                    'organization_id' => $source->organization_id, 'term_id' => $term->id, 'division_id' => $source->division_id,
                    'created_by' => $user->id, 'title' => $source->title, 'type' => $source->type, 'location' => $source->location,
                    'is_mandatory' => $source->is_mandatory, 'starts_at' => $start, 'ends_at' => $shift($source->ends_at),
                    'checkin_opens_at' => $shift($source->checkin_opens_at), 'late_after' => $shift($source->late_after),
                    'checkin_closes_at' => $shift($source->checkin_closes_at),
                ]);
                foreach ($source->targets as $t) {
                    $event->targets()->create(['division_id' => $t->division_id, 'member_id' => $t->member_id]);
                }
                foreach ($extraSessions as $s) {
                    $event->sessions()->create(['title' => $s->title, 'starts_at' => $shift($s->starts_at), 'ends_at' => $shift($s->ends_at)]);
                }
                $out->push($event);
            }

            return $out;
        });
        AuditLogger::log('event.duplicated', 'event', $source->id, ['new_ids' => $created->pluck('id')->all(), 'repeat' => $repeat]);

        return $created;
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

    /**
     * Ubah sesi. Sesi utama: hanya judul (jadwalnya = jadwal kegiatan). Sesi lain: judul & jam;
     * jendela check-in dihitung ulang. Mengubah jam sesi yang check-in-nya sudah dibuka wajib beralasan.
     */
    public function updateSession(Event $event, EventSession $session, array $d, ?string $reason = null): EventSession
    {
        abort_unless($session->event_id === $event->id, 404);
        abort_if($event->closed_at, 422, 'Kegiatan sudah ditutup.');
        $isDefault = $event->defaultSession()->is($session);
        $d = $isDefault ? ['title' => $d['title']] : $d;

        $timeChanged = ! $isDefault && isset($d['starts_at']) && ! $session->starts_at?->equalTo(Carbon::parse($d['starts_at']));
        if ($timeChanged && $session->checkin_opens_at->lte(now())) {
            if (mb_strlen(trim((string) $reason)) < 5) {
                throw ValidationException::withMessages(['reason' => 'Check-in sesi ini sudah dibuka: alasan perubahan jam wajib diisi (min. 5 karakter).']);
            }
        }
        $session->fill($d);
        $session->fill($event->windowFor($session, $isDefault))->save();
        AuditLogger::log('event.session_updated', 'event', $event->id, ['session_id' => $session->id, 'reason' => $reason] + ($timeChanged ? ['starts_at' => $session->starts_at?->toIso8601String()] : []));

        return $session;
    }

    /** Hapus sesi tambahan yang belum punya absensi. Sesi utama tidak bisa dihapus. */
    public function deleteSession(Event $event, EventSession $session): void
    {
        abort_unless($session->event_id === $event->id, 404);
        abort_if($event->closed_at, 422, 'Kegiatan sudah ditutup.');
        abort_if($event->defaultSession()->is($session), 422, 'Sesi utama tidak bisa dihapus.');
        abort_if(Attendance::where('session_id', $session->id)->exists(), 422, 'Sesi ini sudah memiliki absensi; koreksi lewat Daftar Hadir, jangan dihapus.');
        $session->delete();
        AuditLogger::log('event.session_deleted', 'event', $event->id, ['session_id' => $session->id, 'title' => $session->title]);
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
