<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Satu tagihan iuran, nominal sama untuk semua anggota. */
class Due extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'due_on' => 'date:Y-m-d', 'cancelled_at' => 'datetime'];
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function items()
    {
        return $this->hasMany(DueItem::class);
    }
}
