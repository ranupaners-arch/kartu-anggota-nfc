<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class EventDocument extends Model
{
    use BelongsToOrganization;

    protected string $organizationThrough = 'event';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer', 'for_members' => 'boolean'];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
