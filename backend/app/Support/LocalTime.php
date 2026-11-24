<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Waktu dari luar → zona waktu aplikasi. Eloquent menyimpan jam apa adanya tanpa mengonversi zona, jadi
 * "01:00Z" yang tidak dikonversi akan terbaca sebagai 01.00 WIB. Tanpa offset = sudah waktu setempat.
 */
class LocalTime
{
    public static function parse(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    /** Konversi kunci waktu yang terisi di masukan tervalidasi. */
    public static function normalize(array $input, array $keys): array
    {
        foreach ($keys as $k) {
            if (! empty($input[$k]) && is_string($input[$k])) {
                $input[$k] = self::parse($input[$k]);
            }
        }

        return $input;
    }
}
