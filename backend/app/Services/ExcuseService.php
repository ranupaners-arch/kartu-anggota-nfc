<?php

namespace App\Services;

use App\Models\ExcuseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Pengajuan izin/sakit. */
class ExcuseService
{
    public function __construct(private EventService $events) {}

    /** Pengajuan yang boleh dilihat user (Admin Divisi: kegiatan divisinya + kegiatan umum). */
    public function visibleTo(User $user): Builder
    {
        return ExcuseRequest::query()->with(['member:id,member_no,name', 'event:id,title,starts_at,division_id'])
            ->whereHas('event', fn ($q) => $q->when($user->role === User::DIVISION_ADMIN, fn ($q) => $q
                ->where(fn ($w) => $w->whereNull('division_id')->orWhere('division_id', $user->division_id))));
    }

    public function review(User $user, ExcuseRequest $excuse, string $status, ?string $note = null): ExcuseRequest
    {
        $this->events->authorize($user, $excuse->event);
        abort_unless(in_array($status, ['approved', 'rejected'], true), 422, 'Status tidak valid.');

        $excuse->update(['status' => $status, 'review_note' => $note, 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
        AuditLogger::log("excuse.$status", 'excuse', $excuse->id);

        return $excuse->fresh();
    }
}
