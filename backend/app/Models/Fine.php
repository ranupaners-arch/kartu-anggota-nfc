<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Denda absen pakai QR (kartu aktif tertinggal). */
class Fine extends Model
{
    use BelongsToOrganization;

    public string $organizationThrough = 'member';

    public const UNPAID = 'unpaid';

    public const PAID = 'paid';

    public const WAIVED = 'waived';

    public const FREE = 'free';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime', 'amount' => 'integer'];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
