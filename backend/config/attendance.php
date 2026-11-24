<?php

return [
    // Identitas server untuk app (GET /api/instance). Pada server self-hosted biasanya nama organisasi.
    'instance_name' => env('INSTANCE_NAME', env('APP_NAME', 'Kartu Anggota')),
    // Versi kontrak API; app menolak server dengan versi di bawah yang ia butuhkan.
    'api_version' => 1,

    // Prefix payload NDEF/QR. Ganti HANYA sebelum kartu pertama ditulis:
    // kartu yang sudah ditulis tidak akan terbaca bila prefix berubah.
    'payload_prefix' => env('CARD_PAYLOAD_PREFIX', 'card'),

    // Kunci HMAC kartu per key_id (mendukung rotasi). Isi dari environment.
    'card_active_key' => env('CARD_ACTIVE_KEY', 'k1'),
    'card_keys' => [
        'k1' => env('CARD_KEY_k1'),
    ],
    'hmac_bytes' => 12,

    'clock_drift_minutes' => 10,   // toleransi drift scan online
    'qr_ttl_seconds' => 90,        // QR dianggap kedaluwarsa setelah ini
    'qr_rotate_seconds' => 60,     // QR dirotasi bila lebih tua dari ini
    'anomaly_seconds' => 60,       // kartu sama di event/perangkat berbeda
    'held_days' => 7,              // perangkat dicabut masih bisa mengunggah scan offline untuk ditinjau
    'held_max' => 1000,            // batas scan tertahan per perangkat
    'auto_close_grace_minutes' => 5,
    // Berkas ekspor (berisi nama anggota) dihapus setelah sekian hari.
    'export_retention_days' => (int) env('EXPORT_RETENTION_DAYS', 7),
    // Token opsional untuk melihat detail GET /api/health (pesan & waktu tiap pemeriksaan).
    'health_token' => env('HEALTH_TOKEN'),
];
