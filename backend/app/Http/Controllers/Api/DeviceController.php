<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\AuditLogger;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceController extends Controller
{
    public function index()
    {
        return Device::with('user:id,name,email')->orderByDesc('last_seen_at')->get();
    }

    public function revoke(Device $device)
    {
        $device->update(['revoked_at' => now()]);
        if ($device->token_id) {
            PersonalAccessToken::find($device->token_id)?->delete();
        }
        AuditLogger::log('device.revoked', 'device', $device->id);

        return $device->fresh();
    }
}
