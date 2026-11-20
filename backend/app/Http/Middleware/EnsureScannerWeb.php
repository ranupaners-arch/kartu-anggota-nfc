<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Mode petugas di browser (/pindai): Petugas Scan, Admin Divisi, Super Admin yang aktif. Petugas dengan
 * password sementara hanya boleh membuka halaman ganti password sampai diganti.
 */
class EnsureScannerWeb
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || $user->disabled_at || ! in_array($user->role, [User::SCANNER, User::SUPER_ADMIN, User::DIVISION_ADMIN], true)) {
            if ($user?->role === User::MEMBER) {
                return redirect()->route('portal.qr');
            }
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'Halaman pindai hanya untuk petugas & pengurus.']);
        }
        if ($user->must_change_password && $user->role === User::SCANNER && ! $request->routeIs('scanner.password', 'scanner.password.update', 'logout')) {
            return redirect()->route('scanner.password');
        }

        return $next($request);
    }
}
