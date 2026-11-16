<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    /** Koreksi/void: data asli tidak dihapus, perubahan tersimpan di attendance_changes. */
    public function update(Request $r, Attendance $attendance)
    {
        $d = $r->validate([
            'status' => 'required_without:void|in:hadir,telat,izin,sakit,alpa',
            'void' => 'sometimes|boolean', 'reason' => 'required|string|min:3|max:500',
        ]);
        $user = $r->user();
        abort_if($user->role === 'division_admin' && $attendance->event->division_id
            && $attendance->event->division_id !== $user->division_id, 403, 'Bukan kegiatan divisi Anda.');

        DB::transaction(function () use ($attendance, $d, $user) {
            $from = $attendance->voided_at ? 'void' : $attendance->status;
            if (! empty($d['void'])) {
                $attendance->update(['voided_at' => now()]);
                $to = 'void';
            } else {
                $attendance->update(['status' => $d['status'], 'voided_at' => null]);
                $to = $d['status'];
            }
            $attendance->changes()->create(['changed_by' => $user->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $d['reason']]);
            AuditLogger::log('attendance.corrected', 'attendance', $attendance->id, ['from' => $from, 'to' => $to, 'reason' => $d['reason']]);
        });

        return $attendance->fresh()->load('changes');
    }
}
