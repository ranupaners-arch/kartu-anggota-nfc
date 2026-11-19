<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Password;

/**
 * Lupa password mandiri lewat email (hanya bila email server sudah diatur). Jawaban selalu sama,
 * terdaftar atau tidak, agar tidak bisa dipakai menebak email yang terdaftar.
 */
class PasswordResetService
{
    public const SENT = 'Bila email itu terdaftar, tautan untuk membuat password baru sudah dikirim. Periksa kotak masuk (dan folder spam).';
    public const DISABLED = 'Reset password lewat email belum aktif di organisasi ini. Hubungi pengurus untuk reset password.';

    public function sendLink(string $email): void
    {
        abort_unless(MailStatus::enabled(), 503, self::DISABLED);
        $user = User::where('email', mb_strtolower(trim($email)))->whereNull('disabled_at')->first();
        if ($user) {
            Password::broker()->sendResetLink(['email' => $user->email]);
            AuditLogger::log('user.password_reset_requested', 'user', $user->id, [], $user->id);
        }
    }

    /** @return string status broker (Password::PASSWORD_RESET bila berhasil) */
    public function reset(array $d): string
    {
        return Password::broker()->reset($d, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'must_change_password' => false, 'remember_token' => null])->save();
            $user->tokens()->delete(); // keluar dari semua perangkat
            AuditLogger::log('user.password_reset_self', 'user', $user->id, [], $user->id);
        });
    }
}
