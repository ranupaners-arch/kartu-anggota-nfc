<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Scan offline dari perangkat yang dicabut, menunggu tinjauan pengurus. */
class HeldScan extends Model
{
    use BelongsToOrganization;

    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';

    protected $guarded = [];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function device() { return $this->belongsTo(Device::class); }
    public function event() { return $this->belongsTo(Event::class); }
    public function member() { return $this->belongsTo(Member::class); }
}
