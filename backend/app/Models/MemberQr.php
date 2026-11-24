<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class MemberQr extends Model
{
    use BelongsToOrganization;

    public string $organizationThrough = 'member';

    protected $table = 'member_qr';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rotated_at' => 'datetime'];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
