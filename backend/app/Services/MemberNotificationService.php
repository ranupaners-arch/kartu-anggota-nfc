<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\CardRequest;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\Fine;
use App\Models\Membership;
use App\Models\ProfileChange;
use App\Models\Term;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Kotak notifikasi anggota (lonceng di app): dirangkum dari data yang sudah ada, tanpa tabel baru —
 * pengumuman, keputusan izin, keputusan laporan kartu & perubahan data, denda QR, dan pengingat kegiatan
 * 24 jam ke depan.
 * Status "sudah dibaca" disimpan di perangkat anggota (berdasarkan waktu terakhir membuka kotak).
 */
class MemberNotificationService
{
    /** Rentang notifikasi yang ditampilkan. */
    public const DAYS = 30;

    public const LIMIT = 40;

    /** @return list<array{id: string, type: string, title: string, body: ?string, at: string}> */
    public function forMember(int $memberId): array
    {
        $since = now()->subDays(self::DAYS);
        $term = Term::where('is_current', true)->first();

        $items = collect()
            ->merge($this->announcements($memberId, $term, $since))
            ->merge($this->excuses($memberId, $since))
            ->merge($this->cardRequests($memberId, $since))
            ->merge($this->profileChanges($memberId, $since))
            ->merge($this->fines($memberId, $since))
            ->merge($this->reminders($memberId, $term));

        return $items->sortByDesc(fn ($i) => $i['at']->getTimestamp())->take(self::LIMIT)
            ->map(fn ($i) => ['at' => $i['at']->toIso8601String()] + $i)->values()->all();
    }

    private function announcements(int $memberId, ?Term $term, Carbon $since): Collection
    {
        $division = $term ? Membership::where('member_id', $memberId)->where('term_id', $term->id)->value('division_id') : null;

        return Announcement::active()->forDivision($division)->where('created_at', '>=', $since)->latest()->limit(20)->get()
            ->map(fn (Announcement $a) => [
                'id' => "announcement:{$a->id}", 'type' => 'announcement', 'title' => $a->title,
                'body' => str($a->body)->squish()->limit(140)->value(), 'at' => $a->created_at,
            ]);
    }

    private function excuses(int $memberId, Carbon $since): Collection
    {
        return ExcuseRequest::with('event:id,title')->where('member_id', $memberId)->whereIn('status', ['approved', 'rejected'])
            ->where('reviewed_at', '>=', $since)->get()
            ->map(fn (ExcuseRequest $x) => [
                'id' => "excuse:{$x->id}", 'type' => 'excuse',
                'title' => ($x->type === 'sakit' ? 'Sakit' : 'Izin').($x->status === 'approved' ? ' disetujui' : ' ditolak').': '.($x->event?->title ?? 'kegiatan'),
                'body' => $x->review_note, 'at' => $x->reviewed_at,
            ]);
    }

    private function cardRequests(int $memberId, Carbon $since): Collection
    {
        return CardRequest::where('member_id', $memberId)->whereIn('status', ['approved', 'rejected'])
            ->where('reviewed_at', '>=', $since)->get()
            ->map(fn (CardRequest $c) => [
                'id' => "card:{$c->id}", 'type' => 'card',
                'title' => $c->status === 'approved' ? 'Laporan kartu disetujui' : 'Laporan kartu ditolak',
                'body' => $c->review_note ?: ($c->status === 'approved' ? 'Lihat menu Kartu untuk langkah berikutnya.' : 'Hubungi pengurus bila perlu.'),
                'at' => $c->reviewed_at,
            ]);
    }

    private function profileChanges(int $memberId, Carbon $since): Collection
    {
        return ProfileChange::where('member_id', $memberId)->whereIn('status', ['approved', 'rejected'])
            ->where('reviewed_at', '>=', $since)->get()
            ->map(fn (ProfileChange $c) => [
                'id' => "profile:{$c->id}", 'type' => 'profile',
                'title' => $c->status === 'approved' ? 'Perubahan data disetujui' : 'Perubahan data ditolak',
                'body' => $c->review_note ?: ($c->status === 'approved' ? 'Data diri Anda sudah diperbarui.' : 'Data lama tetap dipakai.'),
                'at' => $c->reviewed_at,
            ]);
    }

    private function fines(int $memberId, Carbon $since): Collection
    {
        return Fine::with('attendance.event:id,title')->where('member_id', $memberId)->where('status', '!=', Fine::FREE)
            ->where('created_at', '>=', $since)->get()
            ->map(fn (Fine $f) => [
                'id' => "fine:{$f->id}", 'type' => 'fine',
                'title' => 'Denda absen QR Rp'.number_format($f->amount, 0, ',', '.'),
                'body' => ($f->attendance?->event?->title ?? 'Kegiatan').' — kartu tidak dibawa. Bayar ke pengurus.',
                'at' => $f->created_at,
            ]);
    }

    /** Kegiatan 24 jam ke depan yang belum dihadiri/diizinkan (aturan sama dengan pengingat di app & portal). */
    private function reminders(int $memberId, ?Term $term): Collection
    {
        if (! $term) {
            return collect();
        }
        $events = Event::where('term_id', $term->id)->whereNull('closed_at')
            ->whereBetween('starts_at', [now(), now()->addDay()])->orderBy('starts_at')->get()
            ->filter(fn (Event $e) => $e->targetMembers()->whereKey($memberId)->exists());
        if ($events->isEmpty()) {
            return collect();
        }
        $done = Attendance::whereIn('event_id', $events->pluck('id'))->where('member_id', $memberId)->whereNull('voided_at')->pluck('event_id')
            ->merge(ExcuseRequest::whereIn('event_id', $events->pluck('id'))->where('member_id', $memberId)->pluck('event_id'));

        return $events->reject(fn (Event $e) => $done->contains($e->id))->values()
            ->map(fn (Event $e) => [
                'id' => "reminder:{$e->id}", 'type' => 'reminder', 'title' => 'Pengingat: '.$e->title,
                'body' => collect([$e->starts_at->timezone(config('app.timezone'))->translatedFormat('l, H.i'), $e->location, 'bawa kartu anggota'])->filter()->implode(' · '),
                // Muncul sebagai notifikasi sejak 24 jam sebelum mulai.
                'at' => $e->starts_at->copy()->subDay()->max(now()->subDays(self::DAYS)),
            ]);
    }
}
