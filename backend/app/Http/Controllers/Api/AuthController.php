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

        $device = Device::firstOrNew(['uuid' => $d['device_uuid']]);
        if ($device->isRevoked()) {
            abort(403, 'Perangkat ini sudah dicabut aksesnya.');
        }
        $device->fill(['user_id' => $user->id, 'name' => $d['device_name'], 'last_seen_at' => now()])->save();

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

    private function userData(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role,
            'member_id' => $u->member_id, 'division_id' => $u->division_id];
    }
}
