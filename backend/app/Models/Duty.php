<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Tugas pengurus per periode (Petugas Scan / Admin Divisi) pada akun anggota. */
class Duty extends Model
{
    use BelongsToOrganization;

    public const TYPES = [User::SCANNER, User::DIVISION_ADMIN];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ends_on' => 'date', 'ended_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function division()
    {
        return $this->belongsTo(Division::class);
    }

    public function granter()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function ender()
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /** Belum diakhiri (masa berlaku dicek terpisah oleh DutyService::expire). */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('ended_at');
    }
}
