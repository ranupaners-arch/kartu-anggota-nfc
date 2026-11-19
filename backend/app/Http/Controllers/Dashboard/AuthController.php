<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function show()
    {
        return \Inertia\Inertia::render('auth/login', ['canResetPassword' => \App\Services\MailStatus::enabled()]);
    }

    public function login(Request $r)
    {
        $cred = $r->validate(['email' => 'required|email', 'password' => 'required']);

        if (! Auth::guard('web')->attempt($cred, $r->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }
        $user = Auth::guard('web')->user();
        // Pengurus → dashboard; anggota (akun tertaut) → portal anggota /saya. Petugas scan memakai app.
        $member = $user->role === User::MEMBER && $user->member_id;
        if ($user->disabled_at || (! $user->isAdmin() && ! $member)) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages(['email' => $user->disabled_at
                ? 'Akun dinonaktifkan. Hubungi pengurus.'
                : 'Akun petugas scan dipakai di app, bukan di web.']);
        }

        if (app(TwoFactorService::class)->enabled($user)) {
            // Password benar, tapi belum login penuh: minta kode 2FA dulu.
            Auth::guard('web')->logout();
            $r->session()->put('2fa.pending', ['id' => $user->id, 'remember' => $r->boolean('remember'), 'until' => now()->addMinutes(5)->timestamp]);

            return redirect()->route('login.2fa');
        }

        return $this->completeLogin($r, $user, $r->boolean('remember'));
    }

    public function showTwoFactor(Request $r)
    {
        return $this->pending($r) ? \Inertia\Inertia::render('auth/two-factor') : redirect()->route('login');
    }

    public function twoFactor(Request $r, TwoFactorService $twoFactor)
    {
        $r->validate(['code' => 'required|string|max:20']);
        $pending = $this->pending($r);
        if (! $pending) {
            return redirect()->route('login')->withErrors(['email' => 'Sesi login kedaluwarsa. Masuk lagi.']);
        }
        $user = User::find($pending['id']);
        if (! $user || $user->disabled_at || ! $twoFactor->verify($user, $r->input('code'))) {
            throw ValidationException::withMessages(['code' => 'Kode tidak valid.']);
        }
        $r->session()->forget('2fa.pending');

        return $this->completeLogin($r, $user, $pending['remember']);
    }

    private function pending(Request $r): ?array
    {
        $p = $r->session()->get('2fa.pending');
        if (! $p || $p['until'] < now()->timestamp) {
            $r->session()->forget('2fa.pending');

            return null;
        }

        return $p;
    }

    private function completeLogin(Request $r, User $user, bool $remember)
    {
        Auth::guard('web')->login($user, $remember);
        $r->session()->regenerate();
        AuditLogger::log('auth.dashboard_login', 'user', $user->id, [], $user->id);

        return $user->isAdmin() ? redirect()->intended(route('home')) : redirect()->route('portal.qr');
    }

    public function logout(Request $r)
    {
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }
}
