<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Token Firebase satu HP. Selalu diakses lewat user_id. */
class PushDevice extends Model
{
    protected $guarded = [];

    protected $hidden = ['token', 'token_hash'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
