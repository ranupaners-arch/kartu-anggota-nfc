# Catatan Uji Lapangan

Temuan dari uji di perangkat dicatat di sini dulu, lalu dikumpulkan dan dibuild
sekaligus. APK baru hanya dibuat bila diminta.

## 2026-11-16 · Uji pertama (HP Android, server lewat Tailscale)

APK terpasang: debug dari run `43815488268` (commit sebelum perbaikan di bawah).

| # | Yang diuji | Hasil | Status |
|---|---|---|---|
| 1 | Login petugas di layar Uji QR | Gagal: "connection reset by peer" | Penyebab: URL memakai `https://`, server uji hanya HTTP. Dengan `http://` berhasil |
| 2 | Absensi lewat QR anggota | **Berhasil** (QR dari terminal laptop dipindai HP) | OK |
| 3 | Deteksi tag dengan e-KTP | Muncul menu Android "Pilih tindakan" berisi aplikasi bank | Penyebab: app hanya memegang NFC sesaat (setelah tombol ditekan sampai satu tap); di luar itu Android melempar kartu ke app lain |

## Perbaikan yang sudah ada di kode (belum terpasang di HP)

- **NFC dipegang selama layar terbuka** (reader mode), dilepas saat app ke background.
  Tap tanpa aksi menampilkan "Kartu terdeteksi … pilih aksi dulu". (temuan #3)
- **Layar Uji QR menolak URL selain `http://`** dengan pesan jelas. (temuan #1)
- **APK uji memakai build profile per arsitektur** (±20 MB, bukan debug 181 MB).

## Belum diuji

- Deteksi e-KTP setelah perbaikan #3 (cek juga apakah UID-nya acak per tap).
- Baca/tulis kartu NTAG215 (kartu belum ada).

## Ide perbaikan berikutnya (belum dikerjakan)

- Pesan error jaringan dalam Bahasa Indonesia (mis. "Server tidak terjangkau").
