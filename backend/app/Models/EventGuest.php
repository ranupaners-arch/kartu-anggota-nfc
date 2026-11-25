<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class EventGuest extends Model
{
    use BelongsToOrganization;

    protected string $organizationThrough = 'event';

    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime', 'purged_at' => 'datetime'];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function checkins()
    {
        return $this->hasMany(EventGuestCheckin::class, 'guest_id');
    }
}
