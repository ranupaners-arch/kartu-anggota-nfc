<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/** Halaman iuran: Super Admin, bendahara, dan sekretaris (audit) — apa pun peran akunnya. */
class EnsureFinanceAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_if(! $user || $user->disabled_at || ! $user->canViewDues(), 403, 'Tidak punya akses ke iuran.');
        if ($user->must_change_password) {
            return redirect()->route(match ($user->role) {
                User::MEMBER => 'portal.account',
                User::SCANNER => 'scanner.password',
                default => 'account',
            })->with('status', 'Ganti password sementara Anda dulu.');
        }

        return $next($request);
    }
}
