<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $d = $r->validate([
            'email' => 'required|email', 'password' => 'required',
            'device_uuid' => 'required|string|max:100', 'device_name' => 'required|string|max:100',
            'otp' => 'nullable|string|max:20',
        ]);

        $user = User::where('email', $d['email'])->first();
        if (! $user || ! Hash::check($d['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }
        abort_if($user->disabled_at, 403, 'Akun dinonaktifkan. Hubungi pengurus.');

        $twoFactor = app(\App\Services\TwoFactorService::class);
        if ($twoFactor->enabled($user)) {
            if (empty($d['otp'])) {
                return response()->json(['message' => 'Masukkan kode 2FA dari aplikasi authenticator.', 'otp_required' => true,
                    'errors' => ['otp' => ['Masukkan kode 2FA dari aplikasi authenticator.']]], 422);
            }
            if (! $twoFactor->verify($user, $d['otp'])) {
                return response()->json(['message' => 'Kode 2FA tidak valid.', 'otp_required' => true, 'errors' => ['otp' => ['Kode 2FA tidak valid.']]], 422);
            }
        }

        return $this->issueToken($user, $d['device_uuid'], $d['device_name']);
    }

    /** Daftar akun anggota dari app (hanya selama pendaftaran mandiri dibuka); langsung masuk. */
    public function register(Request $r, \App\Services\RegistrationService $registrations)
    {
        $org = $registrations->defaultOrganization();
        abort_unless($registrations->isOpen($org), 403, 'Pendaftaran akun sedang ditutup. Hubungi pengurus organisasi.');
        $r->merge(['email' => \Illuminate\Support\Str::lower(trim((string) $r->input('email')))]); // unik tanpa beda huruf besar/kecil
        $d = $r->validate([
            'name' => 'required|string|max:150', 'email' => 'required|email|max:150|unique:users,email',
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(10)->letters()->numbers()],
            'device_uuid' => 'required|string|max:100', 'device_name' => 'required|string|max:100',
        ], ['email.unique' => 'Email ini sudah punya akun. Silakan masuk, atau pakai Lupa password.']);
        $user = $registrations->createAccount($org, $d);

        return response()->json($this->issueToken($user, $d['device_uuid'], $d['device_name']), 201);
    }

    /** Token per perangkat (satu token aktif per perangkat). */
    private function issueToken(User $user, string $deviceUuid, string $deviceName): array
    {
        $device = Device::firstOrNew(['uuid' => $deviceUuid]);
        if ($device->isRevoked()) {
            abort(403, 'Perangkat ini sudah dicabut aksesnya.');
        }
        $device->fill(['user_id' => $user->id, 'name' => $deviceName, 'last_seen_at' => now()])->save();

        // Satu token aktif per perangkat.
        if ($device->token_id) {
            $user->tokens()->whereKey($device->token_id)->delete();
        }
        $token = $user->createToken("device:{$device->id}");
        $device->update(['token_id' => $token->accessToken->id]);

        AuditLogger::log('auth.login', 'device', $device->id, [], $user->id);

        return ['token' => $token->plainTextToken, 'device_id' => $device->id, 'user' => $this->userData($user)];
    }

    public function logout(Request $r)
    {
        $r->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $r)
    {
        return $this->userData($r->user());
    }

    /** Lupa password dari app: kirim tautan ke email (halaman atur ulang di web). 503 bila email belum diatur. */
    public function forgot(Request $r, \App\Services\PasswordResetService $resets)
    {
        $r->validate(['email' => 'required|email']);
        $resets->sendLink($r->input('email'));

        return ['message' => \App\Services\PasswordResetService::SENT];
    }

    /** Ganti password dari app (wajib bila password masih sementara). Sesi perangkat lain dikeluarkan. */
    public function changePassword(Request $r)
    {
        $r->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(10)->letters()->numbers()],
        ]);
        $user = $r->user();
        if (! Hash::check($r->input('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Password sekarang salah.']);
        }
        abort_if(Hash::check($r->input('password'), $user->password), 422, 'Password baru harus berbeda dari password sementara.');
        $user->update(['password' => $r->input('password'), 'must_change_password' => false]);
        $user->tokens()->whereKeyNot($user->currentAccessToken()->id)->delete();
        AuditLogger::log('user.password_changed', 'user', $user->id);

        return $this->userData($user->fresh());
    }

    private function userData(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role,
            'member_id' => $u->member_id, 'division_id' => $u->division_id, 'must_change_password' => (bool) $u->must_change_password];
    }
}
