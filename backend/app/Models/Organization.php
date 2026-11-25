<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::created(function (Organization $o) {
            foreach (EventType::DEFAULTS as $name) {
                EventType::create(['organization_id' => $o->id, 'name' => $name]);
            }
        });
    }

    public function terms()
    {
        return $this->hasMany(Term::class);
    }
}
