<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\DeviceService;

class DeviceController extends Controller
{
    public function index()
    {
        return Device::with('user:id,name,email')->orderByDesc('last_seen_at')->get();
    }

    public function revoke(Device $device)
    {
        return app(DeviceService::class)->revoke($device);
    }
}
