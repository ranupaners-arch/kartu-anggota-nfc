<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class EventTarget extends Model
{
    use BelongsToOrganization;

    public string $organizationThrough = 'event';

    protected $guarded = [];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
