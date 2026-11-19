<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Dashboard hanya untuk pengurus (Super Admin / Admin Divisi) yang akunnya aktif. */
class EnsureDashboardAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || $user->disabled_at || ! in_array($user->role, [User::SUPER_ADMIN, User::DIVISION_ADMIN], true)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'Dashboard hanya untuk pengurus dengan akun aktif.']);
        }

        // Password sementara (dibuat/direset pengurus lain): hanya halaman Akun sampai diganti.
        if ($user->must_change_password && ! $request->routeIs('account', 'account.password', 'logout')) {
            return redirect()->route('account')->with('status', 'Ganti password sementara Anda dulu sebelum memakai dashboard.');
        }

        return $next($request);
    }
}
