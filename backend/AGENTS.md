# Panduan agen: backend

Laravel 13 (PHP 8.3+), dashboard & portal Inertia + React + shadcn/ui, PostgreSQL, Valkey/Redis.
Penyiapan lingkungan: README di akar repo. Aturan kontribusi: CONTRIBUTING.md.

## Struktur
- Logika bisnis di `app/Services`; controller API (`Api/`), dashboard, portal, dan pemindai web memakai service yang sama.
- Halaman React di `resources/js/pages`; pakai komponen di `resources/js/components` sebelum membuat baru.
- Data dibatasi per organisasi (`Models/Concerns/BelongsToOrganization`) dan per divisi; jangan dilewati tanpa alasan.

## Aturan
- Format payload kartu (`app/Support/CardPayload.php`) harus sama dengan `app/lib/nfc/card_payload.dart` di app.
- Sinkron batch idempoten lewat `client_uuid`; data absensi tidak dihapus, perubahan dicatat beserta alasannya.
- Kunci kartu (`CARD_KEY_*`), `.env`, dan data anggota asli tidak boleh di-commit.
- Teks antarmuka, komentar, dan commit (Conventional Commits) berbahasa Indonesia.

## Pemeriksaan
- `php artisan test` (perlu PostgreSQL `hmps_nfc_test`), `./vendor/bin/pint --dirty`, `npm run build`.
- Perubahan perilaku wajib disertai tes.
