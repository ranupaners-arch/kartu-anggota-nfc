<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * 2FA berbasis TOTP (aplikasi authenticator) + kode cadangan sekali pakai.
 * Rahasia & hash kode cadangan disimpan terenkripsi (cast 'encrypted').
 */
class TwoFactorService
{
    public const RECOVERY_CODES = 8;

    public function __construct(private Google2FA $totp) {}

    public function enabled(User $user): bool
    {
        return $user->two_factor_secret && $user->two_factor_confirmed_at;
    }

    /** Mulai pendaftaran: buat rahasia baru (belum aktif sampai dikonfirmasi). Kembalikan URL otpauth untuk QR. */
    public function start(User $user): string
    {
        abort_if($this->enabled($user), 422, '2FA sudah aktif.');
        $user->forceFill(['two_factor_secret' => $this->totp->generateSecretKey(32), 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();

        return $this->otpauthUrl($user);
    }

    public function otpauthUrl(User $user): string
    {
        return $this->totp->getQRCodeUrl(config('attendance.instance_name'), $user->email, $user->two_factor_secret);
    }

    /** Konfirmasi dengan kode dari aplikasi; aktifkan & kembalikan kode cadangan (tampil sekali). */
    public function confirm(User $user, string $code): ?array
    {
        if (! $user->two_factor_secret || $this->enabled($user) || ! $this->verifyTotp($user, $code)) {
            return null;
        }
        $codes = $this->newRecoveryCodes($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        AuditLogger::log('user.2fa_enabled', 'user', $user->id, [], $user->id);

        return $codes;
    }

    public function disable(User $user, ?User $actor = null): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        Cache::forget($this->lastStepKey($user));
        AuditLogger::log($actor && ! $actor->is($user) ? 'user.2fa_reset' : 'user.2fa_disabled', 'user', $user->id, [], $actor?->id ?? $user->id);
    }

    /** Kode 6 digit (sekali per langkah waktu) atau kode cadangan (sekali pakai). */
    public function verify(User $user, string $input): bool
    {
        if (! $this->enabled($user)) {
            return false;
        }
        $input = trim($input);
        if (preg_match('/^\d{6}$/', $input)) {
            return $this->verifyTotp($user, $input);
        }

        return $this->useRecoveryCode($user, $input);
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    private function verifyTotp(User $user, string $code): bool
    {
        // Selalu kirim langkah waktu terakhir yang dipakai (bawaan 0): pustaka mengembalikan nomor
        // langkah bila cocok & lebih baru, atau false. Kode yang sama tidak bisa dipakai dua kali.
        $step = $this->totp->verifyKeyNewer($user->two_factor_secret, $code, (int) Cache::get($this->lastStepKey($user), 0), 1);
        if ($step === false) {
            return false;
        }
        Cache::put($this->lastStepKey($user), $step, now()->addMinutes(5));

        return true;
    }

    private function useRecoveryCode(User $user, string $input): bool
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
        $hashes = $user->two_factor_recovery_codes ?? [];
        foreach ($hashes as $i => $hash) {
            if (Hash::check($normalized, $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();
                AuditLogger::log('user.2fa_recovery_used', 'user', $user->id, ['remaining' => count($hashes)], $user->id);

                return true;
            }
        }

        return false;
    }

    /** @return array<string> kode cadangan format XXXX-XXXX */
    private function newRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, self::RECOVERY_CODES))->map(fn () => strtoupper(Str::random(4).'-'.Str::random(4)))->all();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => Hash::make(str_replace('-', '', $c)), $codes)])->save();

        return $codes;
    }

    private function lastStepKey(User $user): string
    {
        return "2fa:last-step:{$user->id}";
    }
}
