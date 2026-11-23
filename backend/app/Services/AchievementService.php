<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Term;

/**
 * Poin & lencana keaktifan anggota periode berjalan — dihitung dari kehadiran (tanpa tabel baru),
 * hanya untuk anggota itu sendiri (tanpa papan peringkat demi privasi).
 */
class AchievementService
{
    /** Poin per status kehadiran. */
    public const POINTS = ['hadir' => 10, 'telat' => 6, 'izin' => 2, 'sakit' => 2, 'alpa' => 0];

    /** Minimal kegiatan sebelum lencana berbasis persentase/tanpa-alpa dinilai. */
    public const MIN_EVENTS = 5;

    public function forMember(int $memberId): array
    {
        $term = Term::where('is_current', true)->first();
        // Satu baris per kegiatan (multi-sesi: sesi terbaik), urut waktu kegiatan.
        $rows = Attendance::with('event:id,starts_at,term_id')->where('member_id', $memberId)->whereNull('voided_at')
            ->when($term, fn ($q) => $q->whereHas('event', fn ($e) => $e->where('term_id', $term->id)))
            ->get()->filter(fn (Attendance $a) => $a->event)
            ->groupBy('event_id')
            ->map(fn ($g) => $g->sortBy(fn (Attendance $a) => array_search($a->status, ['hadir', 'telat', 'izin', 'sakit', 'alpa'], true))->first())
            ->sortBy(fn (Attendance $a) => $a->event->starts_at)->values();

        $statuses = $rows->pluck('status');
        $total = $rows->count();
        $present = $statuses->filter(fn ($s) => in_array($s, ['hadir', 'telat'], true))->count();
        $rate = $total ? $present / $total * 100 : 0;
        $alpa = $statuses->filter(fn ($s) => $s === 'alpa')->count();
        // Hanya kehadiran hasil scan (koreksi manual pengurus tidak dihitung sebagai "pakai QR").
        $scanned = $rows->filter(fn (Attendance $a) => in_array($a->status, ['hadir', 'telat'], true) && in_array($a->source, ['nfc', 'qr'], true));
        $viaCard = $scanned->filter(fn (Attendance $a) => $a->source === 'nfc')->count();

        // Runtun terpanjang & runtun berjalan.
        $streak = fn (callable $ok) => $this->longestAndCurrent($statuses->all(), $ok);
        [$bestOnTime, $nowOnTime] = $streak(fn ($s) => $s === 'hadir');
        [$bestPresent, $nowPresent] = $streak(fn ($s) => in_array($s, ['hadir', 'telat'], true));

        $badges = [
            $this->badge('first', 'Langkah Pertama', 'Hadir di kegiatan pertama periode ini.', $present, 1),
            $this->badge('on_time_5', 'Tepat Waktu', 'Hadir tepat waktu 5× berturut-turut.', $bestOnTime, 5, $nowOnTime),
            $this->badge('streak_10', 'Beruntun', 'Hadir (termasuk telat) 10 kegiatan berturut-turut.', $bestPresent, 10, $nowPresent),
            $this->badge('diligent', 'Rajin', 'Kehadiran minimal 90% (setelah '.self::MIN_EVENTS.' kegiatan).', $total >= self::MIN_EVENTS ? (int) floor($rate) : 0, 90,
                hint: $total < self::MIN_EVENTS ? 'Dinilai setelah '.self::MIN_EVENTS.' kegiatan' : null, earned: $total >= self::MIN_EVENTS && $rate >= 90),
            $this->badge('no_alpa', 'Tanpa Alpa', 'Tidak pernah Alpa (setelah '.self::MIN_EVENTS.' kegiatan).', min($total, self::MIN_EVENTS), self::MIN_EVENTS,
                hint: $alpa > 0 ? "Tercatat Alpa {$alpa}× periode ini" : null, earned: $alpa === 0 && $total >= self::MIN_EVENTS),
            $this->badge('card_loyal', 'Kartu Setia', 'Selalu absen dengan kartu NFC, bukan QR (min. 5×).', $viaCard === $scanned->count() ? $viaCard : 0, 5,
                hint: $viaCard < $scanned->count() ? 'Pernah absen dengan QR periode ini' : null, earned: $viaCard >= 5 && $viaCard === $scanned->count()),
        ];

        return [
            'term' => $term?->name,
            'points' => (int) $statuses->sum(fn ($s) => self::POINTS[$s] ?? 0),
            'earned' => count(array_filter($badges, fn ($b) => $b['earned'])),
            'badges' => $badges,
        ];
    }

    /** @param list<string> $statuses */
    private function longestAndCurrent(array $statuses, callable $ok): array
    {
        $best = $run = 0;
        foreach ($statuses as $s) {
            $run = $ok($s) ? $run + 1 : 0;
            $best = max($best, $run);
        }

        return [$best, $run];
    }

    private function badge(string $key, string $title, string $description, int $progress, int $target, ?int $current = null, ?string $hint = null, ?bool $earned = null): array
    {
        return [
            'key' => $key, 'title' => $title, 'description' => $description,
            'earned' => $earned ?? $progress >= $target,
            // Progres ditampilkan dari runtun berjalan bila ada (yang bisa dikejar), dibatasi target.
            'progress' => min($target, $current ?? $progress), 'target' => $target, 'hint' => $hint,
        ];
    }
}
