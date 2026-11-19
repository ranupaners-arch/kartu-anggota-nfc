<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MailStatus;
use App\Services\PasswordResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Lupa password (web). Halaman atur ulang dipakai semua peran, termasuk anggota pengguna app. */
class PasswordResetController extends Controller
{
    public function __construct(private PasswordResetService $resets) {}

    public function request()
    {
        return Inertia::render('auth/forgot-password', ['enabled' => MailStatus::enabled()]);
    }

    public function email(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        $this->resets->sendLink($r->input('email'));

        return back()->with('status', PasswordResetService::SENT);
    }

    public function show(Request $r, string $token)
    {
        return Inertia::render('auth/reset-password', ['token' => $token, 'email' => (string) $r->query('email')]);
    }

    public function update(Request $r)
    {
        $d = $r->validate([
            'token' => 'required', 'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);
        $status = $this->resets->reset($d);
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Tautan tidak berlaku lagi atau sudah dipakai. Minta tautan baru.']);
        }
        $user = User::where('email', $d['email'])->first();

        return $user?->isAdmin()
            ? redirect()->route('login')->with('status', 'Password diganti. Silakan masuk.')
            : Inertia::render('auth/reset-password', ['done' => true, 'token' => '', 'email' => '']);
    }
}
