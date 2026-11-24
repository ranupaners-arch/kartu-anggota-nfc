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
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime',
            'checkin_opens_at' => 'datetime', 'late_after' => 'datetime', 'checkin_closes_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Jendela check-in dihitung dari kegiatan saat sesi dibuat.
        static::creating(function (EventSession $s) {
            if (! $s->checkin_opens_at) {
                $event = $s->event()->withoutGlobalScopes()->firstOrFail();
                $isDefault = ! static::withoutGlobalScopes()->where('event_id', $s->event_id)->exists();
                $s->fill($event->windowFor($s, $isDefault));
            }
        });
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
