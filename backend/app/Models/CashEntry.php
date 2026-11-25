<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Satu transaksi kas. Isinya tetap; yang boleh berubah hanya tanda batal (sekali). */
class CashEntry extends Model
{
    use BelongsToOrganization;

    public const IN = 'in';

    public const OUT = 'out';

    private const VOID_FIELDS = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'occurred_on' => 'date:Y-m-d', 'voided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (CashEntry $e) {
            if ($e->getOriginal('voided_at') !== null || array_diff(array_keys($e->getDirty()), self::VOID_FIELDS)) {
                throw new LogicException('Transaksi kas tidak bisa diubah, hanya dibatalkan.');
            }
        });
        static::deleting(fn () => throw new LogicException('Transaksi kas tidak bisa dihapus.'));
    }

    public function category()
    {
        return $this->belongsTo(CashCategory::class);
    }

    public function dueItem()
    {
        return $this->belongsTo(DueItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
