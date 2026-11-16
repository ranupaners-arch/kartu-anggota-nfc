<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    /** Koreksi/void: data asli tidak dihapus, perubahan tersimpan di attendance_changes. */
    public function update(Request $r, Attendance $attendance, AttendanceService $service)
    {
        $d = $r->validate([
            'status' => 'required_without:void|in:'.implode(',', AttendanceService::STATUSES),
            'void' => 'sometimes|boolean', 'reason' => 'required|string|min:3|max:500',
        ]);

        return $service->correct($r->user(), $attendance, $d['status'] ?? null, (bool) ($d['void'] ?? false), $d['reason'])
            ->load('changes');
    }
}
