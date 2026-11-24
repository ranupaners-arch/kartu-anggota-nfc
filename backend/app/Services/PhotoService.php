<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Foto anggota diperkecil saat diunggah (sisi terpanjang MAX_SIDE px, JPEG) agar hasil scan
 * di HP tampil cepat walau lewat data seluler. Disimpan di disk privat.
 */
class PhotoService
{
    public const MAX_SIDE = 480;

    public const QUALITY = 82;

    /** Simpan foto yang sudah diperkecil; kembalikan path di disk 'local'. */
    public function store(UploadedFile $file, string $dir): string
    {
        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $src) {
            throw ValidationException::withMessages(['photo' => 'Berkas bukan gambar yang bisa dibaca.']);
        }
        $src = $this->applyExifOrientation($src, $file);

        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];

        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // latar putih untuk PNG transparan
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($dst, null, self::QUALITY);
        $jpeg = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        $path = trim($dir, '/').'/'.Str::random(40).'.jpg';
        Storage::disk('local')->put($path, $jpeg);

        return $path;
    }

    /** Foto dari kamera HP sering berorientasi lewat EXIF; putar bila ekstensi exif tersedia. */
    private function applyExifOrientation(\GdImage $img, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg'], true)) {
            return $img;
        }
        $orientation = (int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1);

        return match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
    }
}
