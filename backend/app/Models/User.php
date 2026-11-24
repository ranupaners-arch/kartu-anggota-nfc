<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'calendar_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const SUPER_ADMIN = 'super_admin';

    public const DIVISION_ADMIN = 'division_admin';

    public const SCANNER = 'scanner';

    public const MEMBER = 'member';

    /** Akses keuangan tambahan (finance_role): bendahara mencatat iuran, sekretaris hanya audit. */
    public const TREASURER = 'treasurer';

    public const AUDITOR = 'auditor';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'disabled_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /** Email lupa password berbahasa Indonesia. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function division()
    {
        return $this->belongsTo(Division::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::SUPER_ADMIN;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [self::SUPER_ADMIN, self::DIVISION_ADMIN], true);
    }

    public function financeTerm()
    {
        return $this->belongsTo(Term::class, 'finance_term_id');
    }

    /** Akses keuangan yang masih berlaku: otomatis berakhir saat periode berganti. */
    public function activeFinanceRole(): ?string
    {
        return $this->finance_role && $this->financeTerm?->is_current ? $this->finance_role : null;
    }

    public function canManageDues(): bool
    {
        return $this->isSuperAdmin() || $this->activeFinanceRole() === self::TREASURER;
    }

    public function canViewDues(): bool
    {
        return $this->isSuperAdmin() || $this->activeFinanceRole() !== null;
    }
}
