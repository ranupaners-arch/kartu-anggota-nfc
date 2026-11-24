<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\TwoFactorService;
use Closure;
use Illuminate\Http\Request;

/**
 * Halaman iuran: Super Admin, bendahara, dan sekretaris (audit) — apa pun peran akunnya.
 * Bendahara & sekretaris wajib 2FA; parameter "setup" = halaman untuk mengaktifkannya.
 */
class EnsureFinanceAccess
{
    public function handle(Request $request, Closure $next, ?string $mode = null)
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

        if ($mode !== 'setup' && ! $user->isSuperAdmin() && ! app(TwoFactorService::class)->enabled($user)) {
            return redirect()->route('dues.security');
        }

        return $next($request);
    }
}
