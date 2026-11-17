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

## 2026-11-16 · Uji kedua (APK profile arm64, commit `8fd3fa2`)

| # | Yang diuji | Hasil | Status |
|---|---|---|---|
| 4 | e-KTP ditempel tanpa menekan tombol | Ditangkap app: "Kartu terdeteksi (IsoDep, NfcA). Pilih aksi dulu" — menu aplikasi bank **tidak** muncul | OK (perbaikan #3 bekerja) |
| 5 | "Deteksi tag" + e-KTP, 2 kali | Keduanya terbaca: IsoDep, NfcA, UID 7 byte diawali `05` | OK |
| 6 | UID e-KTP acak atau tetap? | 7 byte, byte pertama sama di kedua tap → kemungkinan **tetap** (UID acak umumnya 4 byte diawali `08`). Belum pasti karena UID disamarkan | Catatan |
| 7 | Laporan "kartu tidak terdeteksi" | Salah baca hasil; NFC berfungsi normal | Selesai |

## Perbaikan yang sudah ada di kode (sebelumnya belum terpasang di HP)

- **NFC dipegang selama layar terbuka** (reader mode), dilepas saat app ke background.
  Tap tanpa aksi menampilkan "Kartu terdeteksi … pilih aksi dulu". (temuan #3)
- **Layar Uji QR menolak URL selain `http://`** dengan pesan jelas. (temuan #1)
- **APK uji memakai build profile arm64** (36 MB, bukan debug 181 MB).

## Siap diuji (belum terpasang di HP)

- **Mode offline (F9):** matikan data/Wi-Fi di HP saat layar Scan terbuka → tap kartu NTAG
  → hasil berlabel "Offline" + "N scan belum tersinkron"; nyalakan lagi → tersinkron otomatis
  (≤30 detik) atau tombol "Sinkron". Butuh kartu NTAG; QR memang butuh internet.
- **Hubungkan organisasi:** dashboard → Pengaturan → Aplikasi → scan QR dari app.
- **Kunci tanda tangan tetap:** uninstall app lama sekali; APK berikutnya cukup "Perbarui".

## Belum diuji

- Baca/tulis kartu NTAG215 (kartu belum ada).

## Ide perbaikan berikutnya (belum dikerjakan)

- Pesan error jaringan dalam Bahasa Indonesia (mis. "Server tidak terjangkau").
