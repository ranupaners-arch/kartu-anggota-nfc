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
}
