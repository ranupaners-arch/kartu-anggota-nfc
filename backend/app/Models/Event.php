<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime',
            'checkin_opens_at' => 'datetime', 'late_after' => 'datetime',
            'checkin_closes_at' => 'datetime', 'closed_at' => 'datetime',
            'is_mandatory' => 'boolean',
        ];
    }

    public function sessions() { return $this->hasMany(EventSession::class); }
    public function targets() { return $this->hasMany(EventTarget::class); }
    public function attendances() { return $this->hasMany(Attendance::class); }

    public function defaultSession(): EventSession
    {
        return $this->sessions()->orderBy('id')->first()
            ?? $this->sessions()->create(['title' => $this->title, 'starts_at' => $this->starts_at, 'ends_at' => $this->ends_at]);
    }

    /** Anggota yang menjadi target kegiatan (aktif di periode kegiatan). */
    public function targetMembers()
    {
        $q = Member::query()->whereHas('memberships', function ($m) {
            $m->where('term_id', $this->term_id)->where('status', 'active');
        });

        if ($this->targets()->exists()) {
            $divisionIds = $this->targets()->whereNotNull('division_id')->pluck('division_id');
            $memberIds = $this->targets()->whereNotNull('member_id')->pluck('member_id');
            $q->where(function ($w) use ($divisionIds, $memberIds) {
                $w->whereIn('id', $memberIds)->orWhereHas('memberships', fn ($m) => $m
                    ->where('term_id', $this->term_id)->whereIn('division_id', $divisionIds));
            });
        }

        return $q;
    }
}
