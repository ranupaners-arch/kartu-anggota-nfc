<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Tagihan iuran satu anggota. */
class DueItem extends Model
{
    use BelongsToOrganization;

    public const UNPAID = 'unpaid';

    public const PAID = 'paid';

    public const WAIVED = 'waived';

    public string $organizationThrough = 'due';

    protected $guarded = [];

    public function due()
    {
        return $this->belongsTo(Due::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function changes()
    {
        return $this->hasMany(DueItemChange::class);
    }

    public function lastChange()
    {
        return $this->hasOne(DueItemChange::class)->latestOfMany();
    }
}
