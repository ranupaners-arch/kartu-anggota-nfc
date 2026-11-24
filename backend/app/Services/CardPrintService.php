<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Organization;
use App\Models\Term;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Desain kartu anggota siap cetak (ukuran CR80 85,6 × 54 mm, seperti kartu ATM/NFC).
 * - format "a4": 10 kartu per lembar A4 dengan garis potong; halaman sisi belakang kolomnya ditukar
 *   agar pas saat dicetak bolak-balik (balik di sisi panjang).
 * - format "kartu": satu halaman per sisi, untuk printer kartu PVC.
 */
class CardPrintService
{
    public const FORMATS = ['a4', 'kartu'];

    public const PER_PAGE = 10;

    /** @return array{0:string,1:string} [bytes PDF, nama berkas] */
    public function pdf(Collection $members, Term $term, Organization $org, string $format = 'a4'): array
    {
        $cards = $members->map(fn (Member $m) => [
            'name' => $m->name,
            'member_no' => $m->member_no,
            'division' => $m->memberships->firstWhere('term_id', $term->id)?->division?->name,
            'photo' => $this->photoDataUri($m->photo_path),
            'initials' => collect(preg_split('/\s+/', trim($m->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode(''),
            'card_no' => $m->activeCard ? strtoupper(substr($m->activeCard->public_id, -8)) : null,
        ])->values();

        $view = [
            'cards' => $cards, 'format' => $format, 'org' => $org->name, 'term' => $term,
            'logo' => 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents(public_path('favicon.svg'))),
            'pages' => $format === 'a4' ? $cards->chunk(self::PER_PAGE)->map->values() : $cards->map(fn ($c) => collect([$c])),
        ];
        $paper = $format === 'a4' ? 'a4' : [0, 0, 242.65, 153.07]; // 85,6 × 54 mm dalam pt
        $name = $cards->count() === 1 ? 'kartu-'.str($cards[0]['member_no'])->slug().'.pdf' : 'kartu-anggota-'.$cards->count().'.pdf';

        return [Pdf::loadView('exports.kartu', $view)->setPaper($paper, 'portrait')->output(), $name];
    }

    /** Foto dari disk privat → JPEG data URI kecil (dompdf tidak bisa membaca disk privat langsung). */
    private function photoDataUri(?string $path): ?string
    {
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $src = @imagecreatefromstring(Storage::disk('local')->get($path));
        if (! $src) {
            return null;
        }
        // Potong tengah ke rasio bingkai foto (21 × 26 mm) agar tidak gepeng; dompdf tak mendukung object-fit.
        [$w, $h] = [imagesx($src), imagesy($src)];
        $ratio = 21 / 26;
        [$cw, $ch] = $w / $h > $ratio ? [(int) round($h * $ratio), $h] : [$w, (int) round($w / $ratio)];
        $img = imagecreatetruecolor(420, 520);
        imagecopyresampled($img, $src, 0, 0, intdiv($w - $cw, 2), intdiv($h - $ch, 2), 420, 520, $cw, $ch);
        ob_start();
        imagejpeg($img, null, 85);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }
}
