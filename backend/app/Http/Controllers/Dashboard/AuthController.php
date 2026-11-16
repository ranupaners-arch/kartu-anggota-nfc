<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $r)
    {
        $cred = $r->validate(['email' => 'required|email', 'password' => 'required']);

        if (! Auth::guard('web')->attempt($cred, $r->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }
        $user = Auth::guard('web')->user();
        if ($user->disabled_at || ! in_array($user->role, [User::SUPER_ADMIN, User::DIVISION_ADMIN], true)) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages(['email' => 'Dashboard hanya untuk pengurus dengan akun aktif.']);
        }

        $r->session()->regenerate();
        AuditLogger::log('auth.dashboard_login', 'user', $user->id, [], $user->id);

        return redirect()->intended(route('home'));
    }

    public function logout(Request $r)
    {
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }
}
