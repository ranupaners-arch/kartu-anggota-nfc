<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HealthService;
use Illuminate\Http\Request;

/**
 * GET /api/health untuk pemantau uptime. Tanpa token: hanya status keseluruhan & status per
 * pemeriksaan. Dengan ?token=HEALTH_TOKEN (atau header X-Health-Token): beserta pesan & waktu.
 * HTTP 503 bila ada pemeriksaan yang gagal.
 */
class HealthController extends Controller
{
    public function __invoke(Request $r, HealthService $health)
    {
        $result = $health->check();
        $token = (string) config('attendance.health_token');
        $given = (string) ($r->header('X-Health-Token') ?? $r->query('token'));
        if ($token === '' || ! hash_equals($token, $given)) {
            $result['checks'] = array_map(fn ($c) => ['status' => $c['status']], $result['checks']);
        }

        return response()->json($result, $result['status'] === 'fail' ? 503 : 200)->header('Cache-Control', 'no-store');
    }
}
