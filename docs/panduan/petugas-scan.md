# Panduan Petugas Scan

Tugas Anda: mencatat kehadiran anggota dengan menempelkan kartu ke HP (atau memindai QR anggota).

## Sebelum acara (cukup sekali)

1. Pasang app **Kartu Anggota** di HP Android yang punya **NFC**.
2. **Hubungkan ke organisasi**: minta pengurus membuka dashboard → **Pengaturan** → **Aplikasi**,
   lalu di app ketuk **Scan QR dari dashboard** dan arahkan kamera ke QR tersebut.
   (Bisa juga memindai QR itu langsung dengan kamera HP, atau mengetik alamat server.)
3. **Masuk** dengan email & password dari pengurus.
4. Aktifkan **NFC** di pengaturan HP.

## Akun petugas = akun anggota Anda

- Tugas Petugas Scan diberikan Super Admin **per periode** pada akun anggota Anda — tidak perlu akun lain.
  Di app Anda tetap melihat kartu, QR, izin, dan riwayat sebagai anggota, ditambah tab **Tugas** berisi
  beranda petugas di bawah ini. (HP bersama, mis. milik sekretariat, memakai akun petugas khusus dan
  langsung membuka beranda petugas.)
- Saat tugas diberi atau berakhir (dicabut, mengundurkan diri, tanggal berakhir, atau periode berganti),
  Anda menerima notifikasi dan **dikeluarkan dari app** — masuk lagi, tab Tugas muncul/hilang sesuai.
- Anda **tidak bisa memindai kartu/QR sendiri**; minta petugas lain mencatat kehadiran Anda.

## Beranda petugas

- Sapaan & peran Anda, lalu menu cepat **Pindai · Alat NFC · Panduan** (Super Admin juga **Terbitkan
  Kartu**).
- Bila ada **scan offline yang belum tersinkron**, jumlahnya tampil di Beranda dan terkirim
  otomatis saat ada sinyal, termasuk untuk kegiatan yang sudah tutup.
- **Panduan** berisi tips singkat (scan kartu, kartu tertinggal, tanpa sinyal, kartu ditolak, NFC
  tidak membaca). Di tablet, menu di kiri dan kegiatan di kanan.

## Saat acara

1. Di **Beranda**, bagian **Kegiatan berlangsung**, ketuk **Mulai scan** pada kegiatan yang sedang
   berjalan (atau menu cepat **Pindai**: langsung ke kegiatan bila hanya satu, atau pilih dulu bila
   lebih). Kegiatan baru muncul saat jam check-in dibuka — tarik layar ke bawah atau ketuk **Muat ulang**.
2. Bila kegiatan punya beberapa **Sesi** (mis. Hari 1, Hari 2), pastikan sesi yang terpilih benar.
   App otomatis memilih sesi yang sedang buka.
3. Pilih **Kartu NFC**, lalu minta anggota **menempelkan kartu ke bagian belakang HP** sampai
   terdengar bunyi. HP langsung siap untuk kartu berikutnya.
4. **Cocokkan foto** yang muncul dengan wajah orangnya. Ketuk foto untuk memperbesar.

### Arti hasil scan

| Tampilan | Arti | Yang dilakukan |
|---|---|---|
| Hijau **Hadir** | Tercatat tepat waktu | Lanjut |
| Kuning **Telat** | Tercatat, lewat batas telat | Lanjut |
| Biru **Sudah absen** | Kartu ini sudah tercatat di sesi ini | Lanjut; tidak tercatat dua kali |
| Merah **Kartu diblokir** | Kartu dilaporkan hilang/diganti | Arahkan ke pengurus |
| Merah **Tidak dikenali** | Bukan kartu anggota organisasi ini | Minta kartu yang benar atau pakai QR |
| Merah **Bukan peserta** | Anggota bukan target kegiatan ini | Arahkan ke pengurus |
| Merah **Kartu sendiri** | Petugas memindai kartu/QR miliknya sendiri | Minta petugas lain |
| Merah **Di luar jam check-in** | Check-in belum dibuka / sudah ditutup | Arahkan ke pengurus |
| Merah **Jam HP tidak sesuai** | Jam HP berbeda jauh dari server | Aktifkan jam otomatis di HP |

Bunyi: dua nada naik = hadir, dua ketukan = telat/sudah absen, nada rendah = ditolak.
Tombol speaker di kanan atas membisukan bunyi; getar tetap jalan.

### Kartu tertinggal

Minta anggota membuka app di HP-nya → tab **QR Saya**. Di app Anda pilih **QR**, lalu arahkan kamera
ke QR itu. QR berganti tiap menit, jadi tangkapan layar tidak bisa dipakai. **QR butuh internet.**

### Internet putus (mode offline)

- Buka layar Scan kegiatan **sekali saat masih online** sebelum acara/di awal acara — app menyimpan
  daftar peserta dan foto mereka di HP.
- Saat offline, scan kartu tetap jalan. Hasil berlabel **Offline** dan muncul
  "**N scan belum tersinkron**".
- Saat internet kembali, data terkirim otomatis (≤ 30 detik) atau ketuk **Sinkron**.
  Jangan keluar akun sebelum semua scan tersinkron: scan terikat ke akun Anda dan baru terkirim setelah
  Anda masuk lagi di HP itu.
- Scan yang sama tidak akan tercatat ganda walau terkirim dua kali.
- Bila server menolak scan offline (mis. kartu sudah diblokir), Beranda menampilkan **N scan ditolak
  server** → **Lihat rincian** berisi nama, kegiatan, dan alasannya. Laporkan ke pengurus bila perlu
  dikoreksi, lalu ketuk **Sudah ditangani**.

## Tanpa app: pindai QR lewat browser (iPhone / HP tanpa NFC)

Petugas tanpa HP Android ber-NFC tetap bisa memindai **QR Saya** anggota:

1. Buka alamat server di browser HP, **Masuk** dengan akun petugas → otomatis ke halaman **Pindai QR**
   (pengurus: tombol **Pindai QR (browser)** di halaman kegiatan).
2. Pilih kegiatan yang **Dibuka**, izinkan akses kamera.
3. Arahkan kamera ke QR Saya anggota. Hasil langsung tampil: **hijau** Hadir/Telat, **kuning** sudah
   tercatat, **merah** ditolak (QR kedaluwarsa, bukan peserta, di luar jendela check-in) — disertai
   getar & bunyi. Cocokkan foto bila ada.

Catatan: perlu internet (tidak ada mode offline) dan alamat **https://**; kartu NFC tetap lewat app
Android.

## Kendala umum

| Masalah | Solusi |
|---|---|
| "NFC tidak siap" | Aktifkan NFC di pengaturan HP, lalu kembali ke app |
| Kartu tidak terbaca | Tempel tepat di tengah belakang HP, tahan 1–2 detik; lepas casing tebal/logam |
| Muncul menu aplikasi lain saat kartu ditempel | Buka layar Scan app ini dulu, baru tempel kartu |
| "Server tidak terjangkau" | Periksa internet. Lanjutkan scan kartu (offline), sinkron nanti |
| "Sesi berakhir. Silakan masuk lagi." | Keluar lalu masuk lagi (sinkronkan dulu bila ada scan tertunda) |
| Kegiatan tidak muncul | Jam check-in belum dibuka, atau Anda bukan petugas organisasi itu |
