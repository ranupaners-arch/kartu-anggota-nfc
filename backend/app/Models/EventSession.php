<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class EventSession extends Model
{
    use BelongsToOrganization;

    public string $organizationThrough = 'event';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function event() { return $this->belongsTo(Event::class); }
}
