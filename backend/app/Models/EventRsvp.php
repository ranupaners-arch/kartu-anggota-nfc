<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Jawaban anggota: ikut, daftar tunggu, atau tidak ikut. */
class EventRsvp extends Model
{
    use BelongsToOrganization;

    public const GOING = 'going';

    public const WAITLIST = 'waitlist';

    public const DECLINED = 'declined';

    protected string $organizationThrough = 'event';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime'];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
