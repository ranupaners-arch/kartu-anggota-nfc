<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

/** Perlu Ditinjau: konflik offline, override di luar jendela, anomali kartu. */
class ReviewController extends Controller
{
    public function __construct(private AttendanceService $attendances) {}

    public function index(Request $r)
    {
        $tab = $r->query('tab', 'offline');
        $kind = ['offline' => 'offline', 'override' => 'override', 'anomali' => 'anomaly'][$tab] ?? 'offline';

        return view('reviews.index', ['tab' => $tab, 'items' => $this->attendances->reviewQueue($r->user(), $kind)->paginate(30)->withQueryString()]);
    }

    public function accept(Request $r, Attendance $attendance)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:500']);
        $this->attendances->accept($r->user(), $attendance, $d['reason']);

        return back()->with('status', 'Absensi diterima.');
    }

    public function void(Request $r, Attendance $attendance)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:500']);
        $this->attendances->correct($r->user(), $attendance, null, true, $d['reason']);

        return back()->with('status', 'Absensi dibatalkan.');
    }
}
