<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\DeviceService;
use Inertia\Inertia;

class DeviceController extends Controller
{
    public function index()
    {
        return Inertia::render('devices/index', ['devices' => Device::with('user:id,name,email,role')->orderByRaw('revoked_at is not null')->latest('last_seen_at')->paginate(30)]);
    }

    public function revoke(Device $device, DeviceService $devices)
    {
        $devices->revoke($device);

        return back()->with('status', "Akses perangkat \"{$device->name}\" dicabut.");
    }
}
