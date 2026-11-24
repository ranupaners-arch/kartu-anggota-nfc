<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Riwayat perubahan status iuran (audit): tidak bisa diubah atau dihapus lewat aplikasi. */
class DueItemChange extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    public string $organizationThrough = 'item';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Riwayat iuran tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Riwayat iuran tidak bisa dihapus.'));
    }

    public function item()
    {
        return $this->belongsTo(DueItem::class, 'due_item_id');
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
