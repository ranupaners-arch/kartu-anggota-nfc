<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Langganan Web Push satu browser/perangkat milik seorang pengguna. */
class PushSubscription extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user() { return $this->belongsTo(User::class); }
}
