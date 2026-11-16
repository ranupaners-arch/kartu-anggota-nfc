<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    use BelongsToOrganization;

    public string $organizationThrough = 'member';

    protected $guarded = [];

    public function member() { return $this->belongsTo(Member::class); }
    public function term() { return $this->belongsTo(Term::class); }
    public function division() { return $this->belongsTo(Division::class); }
}
