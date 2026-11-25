<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    use BelongsToOrganization;

    public string $organizationThrough = 'event';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'revoked_at' => 'datetime', 'auto_revoked' => 'boolean', 'seq' => 'integer'];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    /** Kode dikelompokkan agar mudah dibaca: ABCD-EFGH-JKMN-PQRS. */
    public function displayCode(): string
    {
        return implode('-', str_split($this->code, 4));
    }
}
