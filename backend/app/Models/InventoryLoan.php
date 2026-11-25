<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class InventoryLoan extends Model
{
    use BelongsToOrganization;

    public const CONDITIONS = ['good', 'damaged', 'lost'];

    protected string $organizationThrough = 'item';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'due_on' => 'date:Y-m-d', 'returned_at' => 'datetime'];
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function lender()
    {
        return $this->belongsTo(User::class, 'lent_by');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isOverdue(): bool
    {
        return ! $this->returned_at && $this->due_on->lt(today());
    }
}
