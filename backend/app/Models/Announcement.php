<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['pinned' => 'boolean', 'expires_at' => 'datetime'];
    }

    public function division()
    {
        return $this->belongsTo(Division::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Belum kedaluwarsa. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** Untuk semua anggota atau divisi tertentu. */
    public function scopeForDivision(Builder $q, ?int $divisionId): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('division_id')->when($divisionId, fn ($x) => $x->orWhere('division_id', $divisionId)));
    }
}
