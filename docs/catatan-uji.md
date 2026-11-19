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

## 2026-11-17 · Uji ketiga (APK `1.0.0-dev.8`, commit `7e86ad8`)

APK pertama dengan kunci tanda tangan tetap (SHA-256 `B6:AD:47:45…DF:8D`):
app lama di-uninstall sekali; APK berikutnya cukup "Perbarui".

| # | Yang diuji | Hasil | Status |
|---|---|---|---|
| 8 | Sambung ke server | Awalnya gagal: Tailscale di HP mati ("offline, last seen 22h ago"). Setelah Tailscale aktif: tersambung | OK (bukan bug app). Saran: aktifkan "Always-on VPN" untuk Tailscale |
| 9 | Layar Hubungkan | Pengguna bertanya "nama organisasi apa?": contoh `absen.organisasi.id` di kolom alamat disangka tempat isi nama organisasi | Bukan masalah: salah baca pengguna, layar sudah jelas |
| 10 | Daftar kegiatan (mode gelap HP) | Kegiatan RAKERNAS (dibuat dari dashboard) tampil rapi. Judul "Kegiatan Berlangsung" dan teks "Masuk sebagai Petugas Scan" hampir tak terbaca (gelap di atas gelap) | **Diperbaiki di kode** (belum dibuild): warna teks kini mengikuti skema terang/gelap di semua layar; tes kontras ≥ 4,5:1 di kedua mode |

## Siap diuji (belum terpasang di HP)

- **Foto anggota di hasil scan:** unggah foto anggota di dashboard (Anggota → detail → Unggah foto),
  lalu scan QR/kartu anggota itu → foto tampil besar di kartu hasil; ketuk untuk memperbesar.
  Offline: foto tetap tampil bila layar Scan kegiatan itu pernah dibuka saat online (foto peserta
  diunduh ke HP di latar belakang). Ganti foto di dashboard → buka layar Scan lagi saat online → foto baru.
- **Mode gelap (#10):** semua teks terbaca.
- **App anggota:** login `anggota@hmps.test` / `password` → tab **QR Saya** (QR berganti tiap menit;
  scan dari HP lain sebagai petugas), **Kegiatan** (status + Ajukan izin dengan lampiran gambar/PDF),
  **Riwayat** (persentase + daftar). Lalu setujui/tolak izinnya di dashboard → Pengajuan Izin.
- **2FA admin:** dashboard → Akun Saya → Aktifkan 2FA → scan QR dengan aplikasi authenticator → simpan
  kode cadangan. Keluar lalu masuk lagi → diminta kode. Di app: login akun itu → muncul kolom Kode 2FA.
- **Privasi:** Tambah anggota → wajib centang persetujuan (juga saat impor CSV). Detail anggota alumni →
  Privasi → Hapus data pribadi (ketik nomor anggota) → nama jadi "Anggota terhapus", rekap tetap.
  Pengaturan → Privasi: atur masa retensi & lihat jumlah data yang akan dihapus.
- **Absensi per sesi:** dashboard → kegiatan → tab Sesi → Tambah sesi (mis. "Hari 2", jam mulai besok).
  Di app, buka kegiatan itu → muncul pilihan sesi (otomatis sesi yang sedang buka). Scan di tiap sesi
  tercatat terpisah; Daftar Hadir & Monitor bisa dipilih per sesi; rekap menghitung tiap sesi.
  Anggota: tab Kegiatan menampilkan "Hadir 1/2 sesi".
- **Pesan error berbahasa Indonesia:** mis. login dengan email salah format, atau matikan server
  (`php artisan serve` dihentikan) saat di layar Kegiatan → pesan dalam Bahasa Indonesia.
- **Bunyi saat scan:** hadir = dua nada naik, telat/sudah absen = dua ketukan, ditolak = nada rendah.
  Tombol speaker di kanan atas layar Scan untuk membisukan (diingat). Getar tetap jalan saat bisu.
- **Monitor kegiatan langsung (web):** dashboard → Kegiatan → pilih kegiatan → "Monitor langsung" (tab baru).
  Buka di laptop/proyektor, lalu scan dari HP → angka & daftar scan bertambah ≤4 detik, baris baru disorot.

- **Mode offline (F9):** matikan data/Wi-Fi di HP saat layar Scan terbuka → tap kartu NTAG
  → hasil berlabel "Offline" + "N scan belum tersinkron"; nyalakan lagi → tersinkron otomatis
  (≤30 detik) atau tombol "Sinkron". Butuh kartu NTAG; QR memang butuh internet.
- **Terbitkan kartu (F3):** login Super Admin → ikon kartu di kanan atas → cari anggota →
  "Terbitkan & tulis kartu" → tempel NTAG215 kosong. Lalu coba scan kartu itu di kegiatan. Butuh kartu NTAG.
- **Hubungkan organisasi:** dashboard → Pengaturan → Aplikasi → scan QR dari app.
  Juga coba pindai QR itu dengan **kamera bawaan HP** (app belum login) → app terbuka di layar Hubungkan
  dan langsung memeriksa server. Bila sedang login ke organisasi lain: muncul penjelasan, sesi tidak diputus.
  Catatan: sebagian aplikasi kamera hanya menampilkan teks untuk tautan selain http(s); Google Lens biasanya bisa.
- **Kunci tanda tangan tetap:** uninstall app lama sekali; APK berikutnya cukup "Perbarui".

## Belum diuji

- Baca/tulis kartu NTAG215 (kartu belum ada).

