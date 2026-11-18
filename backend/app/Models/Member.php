<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $guarded = [];

    public function memberships() { return $this->hasMany(Membership::class); }
    public function cards() { return $this->hasMany(Card::class); }
    public function activeCard() { return $this->hasOne(Card::class)->where('status', 'active'); }
    public function qr() { return $this->hasOne(MemberQr::class); }

    /**
     * URL foto (route bernama) dengan penanda versi: berubah tiap foto diganti, sehingga app &
     * browser boleh menyimpan foto lama di cache tanpa risiko basi. Null bila tanpa foto.
     */
    public function photoUrl(string $route, array $params = []): ?string
    {
        return $this->photo_path ? route($route, $params ?: [$this]).'?v='.substr(md5($this->photo_path), 0, 8) : null;
    }
}
