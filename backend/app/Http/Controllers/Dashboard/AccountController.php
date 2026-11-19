<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function edit(Request $r)
    {
        $user = $r->user()->load('member:id,member_no,name');
        $twoFactor = app(TwoFactorService::class);
        $enrolling = $user->two_factor_secret && ! $user->two_factor_confirmed_at;

        return \Inertia\Inertia::render('account/edit', [
            'user' => $user->only('name', 'email', 'role') + ['member' => $user->member?->only('member_no', 'name')],
            // Tautan langganan jadwal (.ics); null = belum dibuat.
            'calendarUrl' => $user->calendar_token ? route('calendar.feed', ['token' => $user->calendar_token]) : null,
            'twoFactor' => [
                'enabled' => $twoFactor->enabled($user),
                'confirmedAt' => $user->two_factor_confirmed_at,
                'recoveryRemaining' => $twoFactor->remainingRecoveryCodes($user),
                // Hanya saat sedang mendaftar: QR & rahasia untuk aplikasi authenticator.
                'setup' => $enrolling ? ['otpauthUrl' => $twoFactor->otpauthUrl($user), 'secret' => $user->two_factor_secret] : null,
                'recoveryCodes' => $r->session()->get('2fa.recovery_codes'), // tampil sekali setelah aktif
            ],
        ]);
    }

    public function startTwoFactor(Request $r, TwoFactorService $twoFactor)
    {
        $twoFactor->start($r->user());

        return back();
    }

    public function confirmTwoFactor(Request $r, TwoFactorService $twoFactor)
    {
        $r->validate(['code' => 'required|digits:6']);
        $codes = $twoFactor->confirm($r->user(), $r->input('code'));
        if (! $codes) {
            return back()->withErrors(['code' => 'Kode tidak cocok. Pastikan jam HP tepat, lalu coba kode terbaru.']);
        }

        return back()->with('status', '2FA aktif.')->with('2fa.recovery_codes', $codes);
    }

    public function disableTwoFactor(Request $r, TwoFactorService $twoFactor)
    {
        $r->validate(['password' => 'required|current_password:web']);
        $twoFactor->disable($r->user());

        return back()->with('status', '2FA dinonaktifkan.');
    }

    public function password(Request $r)
    {
        $r->validate([
            'current_password' => 'required|current_password:web',
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);
        $r->user()->update(['password' => $r->input('password')]);
        AuditLogger::log('user.password_changed', 'user', $r->user()->id);

        return back()->with('status', 'Password berhasil diganti.');
    }
}
