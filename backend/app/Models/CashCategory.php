<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class CashCategory extends Model
{
    use BelongsToOrganization;

    /** Pilihan awal organisasi baru. */
    public const DEFAULTS = ['in' => ['Iuran', 'Saldo awal', 'Donasi', 'Sponsor', 'Lainnya'], 'out' => ['Konsumsi', 'Perlengkapan', 'Transportasi', 'Sewa', 'Lainnya']];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_dues' => 'boolean'];
    }

    public function entries()
    {
        return $this->hasMany(CashEntry::class, 'category_id');
    }

    public static function seedFor(int $organizationId): void
    {
        foreach (self::DEFAULTS as $kind => $names) {
            foreach ($names as $n) {
                self::create(['organization_id' => $organizationId, 'kind' => $kind, 'name' => $n, 'is_dues' => $kind === 'in' && $n === 'Iuran']);
            }
        }
    }
}
