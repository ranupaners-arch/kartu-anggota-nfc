<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    /** Disaring lewat user.organization_id (User tidak memakai global scope agar login tetap jalan). */
    protected static function booted(): void
    {
        static::addGlobalScope('organization', function (\Illuminate\Database\Eloquent\Builder $q) {
            if ($user = auth()->user()) {
                $q->whereHas('user', fn ($u) => $u->where('organization_id', $user->organization_id));
            }
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user() { return $this->belongsTo(User::class); }
    public function isRevoked(): bool { return $this->revoked_at !== null; }
}
