<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function edit(Request $r)
    {
        $user = $r->user()->load('member:id,member_no,name');

        return \Inertia\Inertia::render('account/edit', ['user' => $user->only('name', 'email', 'role') + ['member' => $user->member?->only('member_no', 'name')]]);
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
