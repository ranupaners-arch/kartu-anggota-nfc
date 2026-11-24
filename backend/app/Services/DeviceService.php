<?php

namespace App\Services;

use App\Models\Device;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceService
{
    /** Cabut perangkat: token dibatalkan, request berikutnya ditolak. */
    public function revoke(Device $device): Device
    {
        $token = $device->token_id ? PersonalAccessToken::find($device->token_id) : null;
        $device->update([
            'revoked_at' => $device->revoked_at ?? now(),
            // Disimpan agar scan offline yang belum terkirim bisa ditahan untuk ditinjau (HeldScanService).
            'held_token_hash' => $token?->token ?? $device->held_token_hash,
            'held_at' => $token ? now() : $device->held_at,
        ]);
        $token?->delete();
        AuditLogger::log('device.revoked', 'device', $device->id);

        return $device->fresh();
    }
}
