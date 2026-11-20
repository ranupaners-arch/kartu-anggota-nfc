<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Portal web anggota (/saya): akun peran Anggota yang aktif dan tertaut ke data anggota. Pengurus
 * diarahkan ke dashboard. Password sementara: hanya halaman Akun sampai diganti.
 */
class EnsureMemberPortal
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user?->isAdmin()) {
            return redirect()->route('home');
        }
        if ($user && ! $user->disabled_at && $user->role === User::SCANNER) {
            return redirect()->route('scanner.index');
        }
        if (! $user || $user->disabled_at || $user->role !== User::MEMBER) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'Akun ini tidak bisa memakai portal anggota.']);
        }
        if ($user->must_change_password && ! $request->routeIs('portal.account', 'portal.password', 'logout')) {
            return redirect()->route('portal.account')->with('status', 'Ganti password sementara Anda dulu.');
        }
        // Belum mengisi e-profil: hanya halaman kartu (kartu kosong), formulir e-profil, dan akun.
        if (! $user->member_id && ! $request->routeIs('portal.qr', 'portal.profile', 'portal.profile.store', 'portal.account', 'portal.password', 'portal.photo', 'logout')) {
            return redirect()->route('portal.qr');
        }

        return $next($request);
    }
}
