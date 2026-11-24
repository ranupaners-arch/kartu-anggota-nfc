<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use BelongsToOrganization;

    public string $organizationThrough = 'event';

    public const HADIR = 'hadir';

    public const TELAT = 'telat';

    public const IZIN = 'izin';

    public const SAKIT = 'sakit';

    public const ALPA = 'alpa';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime', 'synced_at' => 'datetime', 'voided_at' => 'datetime',
            'needs_review' => 'boolean', 'override' => 'boolean',
        ];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function changes()
    {
        return $this->hasMany(AttendanceChange::class);
    }
}
