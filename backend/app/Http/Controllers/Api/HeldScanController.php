<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HeldScanService;
use Illuminate\Http\Request;

/** Unggahan scan offline dari perangkat yang sudah dicabut (di luar auth:sanctum; token dicek sendiri). */
class HeldScanController extends Controller
{
    public function store(Request $r, HeldScanService $held)
    {
        $device = $held->deviceFor($r->bearerToken());
        abort_unless($device, 403, 'Perangkat ini tidak bisa mengirim scan.');
        $d = $r->validate([
            'scans' => 'required|array|min:1|max:200',
            'scans.*.client_uuid' => 'required|uuid', 'scans.*.event_id' => 'required|integer|min:1',
            'scans.*.payload' => 'required|string|max:300', 'scans.*.scanned_at' => 'required|date|before_or_equal:+10 minutes',
            'scans.*.session_id' => 'nullable|integer|min:1',
        ]);

        return ['results' => $held->hold($device, $d['scans'])];
    }
}
