<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Pengaturan sertifikat satu kegiatan. */
class CertificateBatch extends Model
{
    use BelongsToOrganization;

    public string $organizationThrough = 'event';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['min_sessions' => 'integer'];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
