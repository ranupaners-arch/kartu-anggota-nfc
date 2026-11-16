<?php

namespace App\Services;

use App\Models\Device;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceService
{
    /** Cabut perangkat: token dibatalkan, request berikutnya ditolak. */
    public function revoke(Device $device): Device
    {
        $device->update(['revoked_at' => now()]);
        if ($device->token_id) {
            PersonalAccessToken::find($device->token_id)?->delete();
        }
        AuditLogger::log('device.revoked', 'device', $device->id);

        return $device->fresh();
    }
}
