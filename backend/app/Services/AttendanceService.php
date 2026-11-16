<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Koreksi absensi dan antrean tinjauan. */
class AttendanceService
{
    public const STATUSES = ['hadir', 'telat', 'izin', 'sakit', 'alpa'];

    public function __construct(private EventService $events) {}

    /** Koreksi/void: data asli tidak dihapus, perubahan tersimpan di attendance_changes. */
    public function correct(User $user, Attendance $attendance, ?string $status, bool $void, string $reason): Attendance
    {
        $this->events->authorize($user, $attendance->event);
        abort_if(! $void && ! in_array($status, self::STATUSES, true), 422, 'Status tidak valid.');

        DB::transaction(function () use ($attendance, $status, $void, $reason, $user) {
            $from = $attendance->voided_at ? 'void' : $attendance->status;
            if ($void) {
                $attendance->update(['voided_at' => now(), 'needs_review' => false]);
                $to = 'void';
            } else {
                $attendance->update(['status' => $status, 'voided_at' => null, 'needs_review' => false]);
                $to = $status;
            }
            $attendance->changes()->create(['changed_by' => $user->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason]);
            AuditLogger::log('attendance.corrected', 'attendance', $attendance->id, ['from' => $from, 'to' => $to, 'reason' => $reason]);
        });

        return $attendance->fresh();
    }

    /** Terima absensi yang ditandai perlu ditinjau apa adanya. */
    public function accept(User $user, Attendance $attendance, string $reason): Attendance
    {
        $this->events->authorize($user, $attendance->event);
        $attendance->update(['needs_review' => false]);
        $attendance->changes()->create(['changed_by' => $user->id, 'from_status' => $attendance->status,
            'to_status' => $attendance->status, 'reason' => "Diterima setelah ditinjau: $reason"]);
        AuditLogger::log('attendance.review_accepted', 'attendance', $attendance->id, ['reason' => $reason]);

        return $attendance->fresh();
    }

    /** Absensi yang boleh dilihat user, sesuai divisi. */
    public function visibleTo(User $user): Builder
    {
        return Attendance::query()->with(['member:id,member_no,name', 'event:id,title,division_id'])
            ->whereNull('voided_at')
            ->whereHas('event', fn ($q) => $q->when($user->role === User::DIVISION_ADMIN, fn ($q) => $q
                ->where(fn ($w) => $w->whereNull('division_id')->orWhere('division_id', $user->division_id))));
    }

    /** Kategori tinjauan: offline (needs_review), override (di luar jendela), anomali (dari audit log). */
    public function reviewQueue(User $user, string $kind)
    {
        return match ($kind) {
            'override' => $this->visibleTo($user)->where('override', true)->latest('scanned_at'),
            'anomaly' => AuditLog::query()->where('action', 'scan.anomaly')
                ->whereIn('user_id', User::where('organization_id', $user->organization_id)->select('id'))->latest('created_at'),
            default => $this->visibleTo($user)->where('needs_review', true)->latest('scanned_at'),
        };
    }

    public function pendingReviewCount(User $user): int
    {
        return $this->visibleTo($user)->where('needs_review', true)->count();
    }
}
