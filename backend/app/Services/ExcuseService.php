<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Pengajuan izin/sakit. */
class ExcuseService
{
    public function __construct(private EventService $events, private AttendanceService $attendances) {}

    /**
     * Ajukan/ubah izin. Anggota: untuk dirinya, selama kegiatan belum ditutup. Pengurus: atas nama
     * anggota peserta kegiatan yang boleh ia kelola, juga setelah kegiatan ditutup (retroaktif).
     */
    public function submit(User $actor, Event $event, int $memberId, string $type, string $reason, ?UploadedFile $attachment = null): ExcuseRequest
    {
        if ($actor->isAdmin()) {
            $this->events->authorize($actor, $event);
        } else {
            abort_unless($actor->member_id === $memberId, 403);
            abort_if($event->closed_at, 422, 'Kegiatan sudah ditutup; hubungi admin.');
        }
        abort_unless($event->targetMembers()->whereKey($memberId)->exists(), 422, 'Anggota ini bukan peserta kegiatan tersebut.');
        abort_if(Attendance::where('event_id', $event->id)->where('member_id', $memberId)->whereNull('voided_at')
            ->whereIn('status', ['hadir', 'telat'])->count() >= $event->sessions()->count(), 422, 'Sudah tercatat hadir di kegiatan ini.');

        $excuse = ExcuseRequest::firstOrNew(['event_id' => $event->id, 'member_id' => $memberId]);
        // Yang sudah diputuskan tidak bisa dibuka ulang lewat pengajuan baru.
        abort_if($excuse->exists && $excuse->status !== 'pending', 422, 'Pengajuan untuk kegiatan ini sudah diputuskan.');

        $old = $excuse->attachment_path;
        $excuse->fill(['type' => $type, 'reason' => $reason, 'status' => 'pending']);
        if ($attachment) {
            $excuse->attachment_path = $attachment->store("excuses/{$event->organization_id}", 'local');
        }
        $excuse->save();
        if ($old && $old !== $excuse->attachment_path) {
            Storage::disk('local')->delete($old); // lampiran lama diganti
        }
        AuditLogger::log($event->closed_at ? 'excuse.retroactive' : 'excuse.submitted', 'excuse', $excuse->id,
            $actor->isAdmin() ? ['by_admin' => true] : [], $actor->id);

        return $excuse;
    }

    /** Pengajuan yang boleh dilihat user (Admin Divisi: kegiatan divisinya + kegiatan umum). */
    public function visibleTo(User $user): Builder
    {
        return ExcuseRequest::query()->with(['member:id,member_no,name', 'event:id,title,starts_at,division_id'])
            ->whereHas('event', fn ($q) => $q->when($user->role === User::DIVISION_ADMIN, fn ($q) => $q
                ->where(fn ($w) => $w->whereNull('division_id')->orWhere('division_id', $user->division_id))));
    }

    /**
     * Setujui/tolak banyak pengajuan sekaligus. Hanya yang masih menunggu dan terlihat oleh pengguna
     * ini yang diproses (sisanya dilewati); semua atau tidak sama sekali. Mengembalikan jumlah diproses.
     */
    public function reviewMany(User $user, array $ids, string $status, ?string $note = null): int
    {
        $excuses = $this->visibleTo($user)->whereIn('id', $ids)->where('status', 'pending')->get();

        return DB::transaction(function () use ($user, $excuses, $status, $note) {
            $excuses->each(fn (ExcuseRequest $x) => $this->review($user, $x, $status, $note));

            return $excuses->count();
        });
    }

    public function review(User $user, ExcuseRequest $excuse, string $status, ?string $note = null): ExcuseRequest
    {
        $this->events->authorize($user, $excuse->event);
        abort_unless(in_array($status, ['approved', 'rejected'], true), 422, 'Status tidak valid.');

        $excuse->update(['status' => $status, 'review_note' => $note, 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
        AuditLogger::log("excuse.$status", 'excuse', $excuse->id);

        // Kegiatan sudah ditutup: Alpa anggota ini diganti Izin/Sakit (tercatat sebagai koreksi).
        if ($status === 'approved' && $excuse->event->closed_at) {
            Attendance::where('event_id', $excuse->event_id)->where('member_id', $excuse->member_id)
                ->whereNull('voided_at')->where('status', Attendance::ALPA)->get()
                ->each(fn (Attendance $a) => $this->attendances->correct($user, $a, $excuse->type, false, 'Izin disetujui setelah kegiatan ditutup'));
        }

        return $excuse->fresh();
    }
}
