<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Permintaan kartu pengganti dari anggota (hilang/rusak), disetujui Super Admin. */
class CardRequest extends Model
{
    use BelongsToOrganization;

    public string $organizationThrough = 'member';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
