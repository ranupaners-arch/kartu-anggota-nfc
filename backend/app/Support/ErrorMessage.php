<?php

namespace App\Support;

/** Terjemahkan pesan error bawaan Laravel/Symfony (bahasa Inggris) yang sampai ke pengguna. */
final class ErrorMessage
{
    private const MAP = [
        'Unauthenticated.' => 'Sesi berakhir. Silakan masuk lagi.',
        'This action is unauthorized.' => 'Anda tidak punya akses untuk tindakan ini.',
        'Too Many Attempts.' => 'Terlalu banyak percobaan. Coba lagi sebentar lagi.',
        'Server Error' => 'Terjadi kesalahan di server. Coba lagi nanti.',
        'Not Found' => 'Data tidak ditemukan.',
        'Forbidden' => 'Anda tidak punya akses untuk tindakan ini.',
        'Unauthorized' => 'Sesi berakhir. Silakan masuk lagi.',
        'Method Not Allowed' => 'Permintaan tidak didukung.',
        'Service Unavailable' => 'Server sedang dalam pemeliharaan. Coba lagi nanti.',
        'Page Expired' => 'Halaman kedaluwarsa. Muat ulang lalu coba lagi.',
        'Payload Too Large' => 'Berkas terlalu besar.',
    ];

    public static function translate(?string $message, int $status = 500): string
    {
        $message = trim((string) $message);
        if ($message === '' || str_starts_with($message, 'No query results for model')) {
            return match (true) {
                $status === 404 => 'Data tidak ditemukan.',
                $status === 403 => self::MAP['Forbidden'],
                $status === 401 => self::MAP['Unauthenticated.'],
                default => 'Permintaan tidak bisa diproses.',
            };
        }

        return self::MAP[$message] ?? $message;
    }
}
