<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    public function term() { return $this->belongsTo(Term::class); }
    public function sessions() { return $this->hasMany(EventSession::class); }
    public function targets() { return $this->hasMany(EventTarget::class); }
    public function attendances() { return $this->hasMany(Attendance::class); }

    protected static function booted(): void
    {
        // Setiap kegiatan punya sesi utama sejak dibuat.
        static::created(fn (Event $e) => $e->sessions()->create(['title' => $e->title, 'starts_at' => $e->starts_at, 'ends_at' => $e->ends_at]));
        // Jadwal kegiatan berubah → jendela semua sesi ikut dihitung ulang.
        static::updated(function (Event $e) {
            if ($e->wasChanged(['starts_at', 'ends_at', 'checkin_opens_at', 'late_after', 'checkin_closes_at'])) {
                $e->syncSessionWindows();
            }
        });
    }

    /** Sesi utama (id terkecil): jendelanya = jendela kegiatan. */
    public function defaultSession(): EventSession
    {
        return $this->sessions()->orderBy('id')->first()
            ?? $this->sessions()->create(['title' => $this->title, 'starts_at' => $this->starts_at, 'ends_at' => $this->ends_at]);
    }

    /**
     * Jendela check-in sebuah sesi. Sesi utama (atau sesi tanpa jam mulai) memakai jendela kegiatan;
     * sesi lain bergeser ke jam mulainya dengan selisih yang sama (mis. buka 30 menit sebelum mulai,
     * telat setelah 10 menit, tutup 1 jam setelah mulai).
     *
     * @return array{checkin_opens_at: Carbon, late_after: Carbon, checkin_closes_at: Carbon}
     */
    public function windowFor(EventSession $session, bool $isDefault): array
    {
        $base = $isDefault || ! $session->starts_at ? $this->starts_at : $session->starts_at;
        $shift = fn (Carbon $t) => $base->copy()->addSeconds((int) round($this->starts_at->diffInSeconds($t, false)));

        return [
            'checkin_opens_at' => $shift($this->checkin_opens_at),
            'late_after' => $shift($this->late_after),
            'checkin_closes_at' => $shift($this->checkin_closes_at),
        ];
    }

    public function syncSessionWindows(): void
    {
        $this->sessions()->orderBy('id')->get()->each(fn (EventSession $s, int $i) => $s->update($this->windowFor($s, $i === 0)));
        $this->unsetRelation('sessions');
    }

    /** Sesi yang jendela check-in-nya mencakup waktu ini (urut sesi), atau null. */
    public function currentSession(?Carbon $at = null): ?EventSession
    {
        $at ??= now();

        return $this->sessions->sortBy('id')->first(fn (EventSession $s) => $at->between($s->checkin_opens_at, $s->checkin_closes_at));
    }

    /** Belum ditutup & ada sesi yang sedang membuka check-in. */
    public function scopeOngoing(Builder $q, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $q->whereNull('closed_at')->whereHas('sessions', fn ($s) => $s
            ->where('checkin_opens_at', '<=', $at)->where('checkin_closes_at', '>=', $at));
    }

    /** Ditutup, atau jendela semua sesinya sudah lewat. */
    public function scopeFinished(Builder $q, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $q->where(fn ($w) => $w->whereNotNull('closed_at')
            ->orWhereDoesntHave('sessions', fn ($s) => $s->where('checkin_closes_at', '>=', $at)));
    }

    /** Jumlah anggota berbeda yang hadir/telat di minimal satu sesi (kolom present_count). */
    public function scopeWithPresentCount(Builder $q): Builder
    {
        return $q->addSelect(['present_count' => DB::table('attendances')->selectRaw('count(distinct member_id)')
            ->whereColumn('attendances.event_id', 'events.id')->whereNull('voided_at')->whereIn('status', ['hadir', 'telat'])]);
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
