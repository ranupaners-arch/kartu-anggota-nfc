<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Pilihan tipe kegiatan organisasi (Pengaturan → Tipe Kegiatan). */
class EventType extends Model
{
    use BelongsToOrganization;

    /** Pilihan awal organisasi baru. */
    public const DEFAULTS = ['Rapat', 'Seminar', 'Pelatihan', 'Sosial'];

    protected $guarded = [];
}
