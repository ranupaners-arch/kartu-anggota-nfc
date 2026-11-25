<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'archived_at' => 'datetime'];
    }

    public function loans()
    {
        return $this->hasMany(InventoryLoan::class, 'item_id');
    }

    /** Jumlah yang sedang dipinjam. */
    public function onLoan(): int
    {
        return (int) $this->loans()->whereNull('returned_at')->sum('quantity');
    }
}
