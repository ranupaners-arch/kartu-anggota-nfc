<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    use BelongsToOrganization;

    public const UNASSIGNED = 'unassigned';
    public const ACTIVE = 'active';
    public const BLOCKED = 'blocked';
    public const LOST = 'lost';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function member() { return $this->belongsTo(Member::class); }

    public function isUsable(): bool { return $this->status === self::ACTIVE; }
}
