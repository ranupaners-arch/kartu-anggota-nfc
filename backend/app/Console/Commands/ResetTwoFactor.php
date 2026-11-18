<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;

/** Darurat: matikan 2FA sebuah akun (mis. Super Admin kehilangan HP & kode cadangan). */
class ResetTwoFactor extends Command
{
    protected $signature = 'app:2fa-reset {email : Email akun}';

    protected $description = 'Nonaktifkan 2FA sebuah akun (darurat, dari server)';

    public function handle(TwoFactorService $twoFactor): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('Akun tidak ditemukan.');

            return self::FAILURE;
        }
        $twoFactor->disable($user);
        $this->info("2FA untuk {$user->email} dinonaktifkan. Login dengan password, lalu aktifkan 2FA lagi.");

        return self::SUCCESS;
    }
}
