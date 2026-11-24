<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\HeldScan;
use App\Services\AttendanceService;
use App\Services\HeldScanService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Perlu Ditinjau: konflik offline, override di luar jendela, anomali kartu. */
class ReviewController extends Controller
{
    public function __construct(private AttendanceService $attendances, private HeldScanService $held) {}

    public function index(Request $r)
    {
        $tab = $r->query('tab', 'offline');
        if ($tab === 'tertahan') {
            $items = $this->held->pending($r->user())->with(['member:id,member_no,name', 'event:id,title', 'device:id,name,user_id,revoked_at', 'device.user:id,name'])
                ->orderBy('scanned_at')->orderBy('id')->paginate(30)->withQueryString();

            return Inertia::render('reviews/index', ['tab' => $tab, 'items' => $items]);
        }
        $kind = ['offline' => 'offline', 'override' => 'override', 'anomali' => 'anomaly'][$tab] ?? 'offline';

        return Inertia::render('reviews/index', ['tab' => $tab, 'items' => $this->attendances->reviewQueue($r->user(), $kind)->paginate(30)->withQueryString()]);
    }

    public function accept(Request $r, Attendance $attendance)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:500']);
        $this->attendances->accept($r->user(), $attendance, $d['reason']);

        return back()->with('status', 'Absensi diterima.');
    }

    public function acceptHeld(Request $r, HeldScan $scan)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:500']);
        $this->held->accept($r->user(), $scan, $d['reason']);

        return back()->with('status', 'Scan diterima dan dicatat.');
    }

    public function rejectHeld(Request $r, HeldScan $scan)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:500']);
        $this->held->reject($r->user(), $scan, $d['reason']);

        return back()->with('status', 'Scan ditolak.');
    }

    public function void(Request $r, Attendance $attendance)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:500']);
        $this->attendances->correct($r->user(), $attendance, null, true, $d['reason']);

        return back()->with('status', 'Absensi dibatalkan.');
    }
}
