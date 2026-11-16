<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Isolasi data per organisasi (multi-tenant).
 *
 * - Model dengan kolom organization_id: disaring langsung, dan organization_id
 *   terisi otomatis saat create.
 * - Model tanpa kolom itu: definisikan $organizationThrough (nama relasi ke model
 *   yang membawa organization_id), disaring lewat whereHas.
 *
 * Tanpa user login (console, queue, seeder) tidak ada penyaringan.
 * User login tanpa organization_id tidak melihat data apa pun.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $q) {
            $user = auth()->user();
            if (! $user) {
                return;
            }
            $orgId = $user->organization_id;
            $model = $q->getModel();
            $through = property_exists($model, 'organizationThrough') ? $model->organizationThrough : null;

            if ($orgId === null) {
                $q->whereRaw('1 = 0');
            } elseif ($through) {
                $q->whereHas($through); // relasi target juga memakai scope ini
            } else {
                $q->where($model->qualifyColumn('organization_id'), $orgId);
            }
        });

        static::creating(function (Model $m) {
            if (! property_exists($m, 'organizationThrough') && ! $m->organization_id) {
                $m->organization_id = auth()->user()?->organization_id;
            }
        });
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
