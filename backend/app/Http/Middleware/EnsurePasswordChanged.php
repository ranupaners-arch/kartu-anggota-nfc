<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * API: akun dengan password sementara (dibuat/direset pengurus) hanya boleh memanggil endpoint
 * profil, ganti password, dan keluar sampai passwordnya diganti.
 */
class EnsurePasswordChanged
{
    public const ALLOWED = ['api/me', 'api/me/password', 'api/auth/logout'];

    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->must_change_password && ! $request->is(...self::ALLOWED)) {
            return response()->json([
                'message' => 'Ganti password sementara Anda dulu.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
