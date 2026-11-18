<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const SUPER_ADMIN = 'super_admin';
    public const DIVISION_ADMIN = 'division_admin';
    public const SCANNER = 'scanner';
    public const MEMBER = 'member';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function member() { return $this->belongsTo(Member::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function division() { return $this->belongsTo(Division::class); }
    public function isSuperAdmin(): bool { return $this->role === self::SUPER_ADMIN; }
    public function isAdmin(): bool { return in_array($this->role, [self::SUPER_ADMIN, self::DIVISION_ADMIN], true); }
}
