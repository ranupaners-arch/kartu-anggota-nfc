<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ExcuseRequest extends Model
{
    use BelongsToOrganization;

    public string $organizationThrough = 'event';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'used' => 'boolean'];
    }

    public function member() { return $this->belongsTo(Member::class); }
    public function event() { return $this->belongsTo(Event::class); }
}
