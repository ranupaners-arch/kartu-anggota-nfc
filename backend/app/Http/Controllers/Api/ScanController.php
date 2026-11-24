<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Event;
use App\Services\ScanContext;
use App\Services\ScanService;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function __construct(private ScanService $scans) {}

    public function scan(Request $r, Event $event)
    {
        $d = $r->validate([
            'payload' => 'required|string|max:300', 'source' => 'sometimes|in:nfc,qr',
            'scanned_at' => 'nullable|date', 'client_uuid' => 'nullable|uuid',
            'session_id' => 'nullable|integer', 'override_reason' => 'nullable|string|max:300',
        ]);

        return $this->scans->process($event, $d, $r->user(), $this->device($r));
    }

    public function batch(Request $r, Event $event)
    {
        $r->validate(['scans' => 'required|array|min:1|max:500']);
        $user = $r->user();
        $device = $this->device($r);

        // Data kegiatan, kartu, dan absensi dimuat sekali untuk seluruh batch.
        $ctx = new ScanContext($event);
        $ctx->prefetch($r->input('scans'));

        $results = collect($r->input('scans'))->map(function ($s) use ($event, $user, $device, $ctx) {
            $res = $this->scans->process($event, $s, $user, $device, fromBatch: true, ctx: $ctx);

            return ['client_uuid' => $s['client_uuid'] ?? null] + $res;
        });

        return ['results' => $results->values(), 'summary' => $results->countBy('status')];
    }

    private function device(Request $r): ?Device
    {
        $name = $r->user()->currentAccessToken()?->name ?? '';
        $device = str_starts_with($name, 'device:') ? Device::find((int) substr($name, 7)) : null;
        $device?->update(['last_seen_at' => now()]);

        return $device;
    }
}
