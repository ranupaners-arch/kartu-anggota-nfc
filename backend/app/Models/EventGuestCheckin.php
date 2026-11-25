<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Kehadiran tamu per sesi; selalu diakses lewat tamunya (yang membawa isolasi organisasi). */
class EventGuestCheckin extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime'];
    }

    public function guest()
    {
        return $this->belongsTo(EventGuest::class);
    }

    public function session()
    {
        return $this->belongsTo(EventSession::class);
    }
}
