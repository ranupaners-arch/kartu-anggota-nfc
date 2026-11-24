<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RegistrationService;

/**
 * Identitas server (publik, tanpa login). Dipakai app untuk memastikan alamat yang
 * dimasukkan/di-scan memang server Kartu Anggota sebelum login.
 * Sengaja tidak menyebut daftar organisasi agar server bersama tidak membocorkan pelanggannya.
 */
class InstanceController extends Controller
{
    public function __invoke()
    {
        return response()->json([
            'app' => 'kartu-anggota',
            'name' => config('attendance.instance_name'),
            'api_version' => config('attendance.api_version'),
            // App menampilkan tombol "Daftar" hanya bila pendaftaran mandiri dibuka.
            'registration_open' => app(RegistrationService::class)->isOpen(app(RegistrationService::class)->defaultOrganization()),
        ])->header('Cache-Control', 'public, max-age=60');
    }
}
