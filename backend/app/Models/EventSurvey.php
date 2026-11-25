<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EventSurvey extends Model
{
    use BelongsToOrganization;

    protected string $organizationThrough = 'event';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['questions' => 'array', 'closes_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function isOpen(): bool
    {
        return ! $this->closed_at && $this->closes_at->isFuture();
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('closed_at')->where('closes_at', '>', now());
    }
}
