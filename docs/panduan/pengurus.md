# Panduan Pengurus (Super Admin & Admin Divisi)

Dashboard dibuka di browser pada alamat server organisasi. Menu yang tampil menyesuaikan peran.
Bagian bertanda **(Super Admin)** hanya tersedia untuk Super Admin.

## Keamanan akun

- **Akun Saya** → ganti password, dan aktifkan **Keamanan (2FA)**: pindai QR dengan aplikasi
  authenticator (Google Authenticator, 2FAS, dll.), masukkan kode 6 digit, lalu **simpan 8 kode
  cadangan** di tempat aman. Sangat disarankan untuk semua pengurus.
- Lupa HP & kode cadangan? Super Admin lain bisa **Reset 2FA** di Pengguna & Peran.

## Menyiapkan data (Super Admin)

### Anggota
- **Anggota** → tambah satu per satu, atau **Impor CSV** (pratinjau dulu, baris bermasalah ditandai).
- Wajib mencentang bahwa anggota **sudah menyetujui** datanya (termasuk foto) diproses.
- Detail anggota → **Unggah foto** (dipakai petugas untuk mencocokkan wajah saat scan).
- Status keanggotaan per periode: Aktif, Tidak aktif, Alumni, Pendaftar. Hanya anggota **Aktif**
  yang bisa absen.
- Anggota yang dihapus masuk tab **Arsip** dan bisa **Pulihkan** (kartu lamanya tetap diblokir).
  Nomor anggota di arsip tidak bisa dipakai anggota baru — pulihkan saja.
- Admin Divisi bisa melihat daftar & detail anggota divisinya (baca-saja).

### Kartu
- Kartu diterbitkan lewat **app** (login Super Admin): ikon kartu di kanan atas → cari anggota →
  **Terbitkan & tulis kartu** → tempel kartu NTAG215 kosong. Kartu lama anggota itu otomatis diblokir.
- Kartu hilang/dicuri: dashboard → **Kartu** → **Hilang** atau **Blokir**. Berlaku seketika.

### Akun petugas & perangkat
- **Pengguna & Peran** → **Tambah Pengguna** → peran *Petugas Scan* (atau *Anggota*, tautkan ke
  nomor anggota). Password sementara tampil sekali.
- **Perangkat Petugas** → **Cabut akses** bila HP petugas hilang (akun di HP itu langsung keluar).
- **Pengaturan** → *Aplikasi*: QR untuk menghubungkan app ke server ini.

## Membuat kegiatan

**Kegiatan** → **Buat Kegiatan**:

| Isian | Keterangan |
|---|---|
| Judul, Tipe, Lokasi | Bebas |
| Mulai / Selesai | Jadwal kegiatan |
| Check-in dibuka | Sejak kapan scan diterima (mis. 30 menit sebelum mulai) |
| Dianggap telat setelah | Scan setelah jam ini tercatat **Telat** |
| Check-in ditutup | Setelah ini scan ditolak |
| Milik divisi | Kosongkan untuk kegiatan umum |
| Target peserta per divisi | Kosongkan semua = seluruh anggota aktif |
| Wajib hadir | Yang tidak scan ditandai **Alpa** saat kegiatan ditutup |

Setelah dibuat, buka kegiatan:
- Tab **Target Peserta**: tambah anggota tertentu (nomor anggota).
- Tab **Sesi**: untuk kegiatan beberapa sesi/hari. Isi judul & jam mulai; jendela check-in sesi
  mengikuti jam mulainya. Tiap sesi dihitung sebagai satu kehadiran di rekap.
- Mengubah jendela check-in kegiatan yang sudah berjalan wajib disertai **alasan** (tercatat).

- **Duplikat** (halaman kegiatan): salin kegiatan ke tanggal baru — target, sesi, dan jendela
  check-in ikut. Bisa **berulang** tiap minggu / 2 minggu / bulan (maks. 12), cocok untuk rapat rutin.

## Pengumuman

**Pengumuman** → **Pengumuman Baru**: judul, isi, untuk semua anggota atau satu divisi, opsional
disematkan dan diberi tanggal kedaluwarsa. Tampil di app anggota (tab Kegiatan). Admin Divisi hanya
untuk divisinya.

## Selama kegiatan

- **Monitor langsung** (tombol di halaman kegiatan, buka di laptop/proyektor): jumlah hadir,
  scan terbaru dengan foto, dan daftar yang belum datang; diperbarui tiap 4 detik.
- **Daftar Hadir**: koreksi status (Hadir/Telat/Izin/Sakit/Alpa) atau **Batalkan** scan yang salah —
  wajib alasan; riwayat koreksi tersimpan.

## Setelah kegiatan

- **Tutup Kegiatan**: anggota wajib yang tidak scan ditandai **Alpa**, atau **Izin/Sakit** bila
  izinnya sudah disetujui. Bila tidak ditutup manual, sistem menutup otomatis ±5 menit setelah
  check-in sesi terakhir berakhir.
- **Pengajuan Izin**: **Setujui** / **Tolak** (lihat lampiran bukti). Bila disetujui setelah
  kegiatan ditutup, status Alpa anggota itu otomatis diganti Izin/Sakit (tercatat sebagai koreksi).
- Anggota mengabarkan izin langsung (tanpa app)? **Pengajuan Izin** → **Catat Izin**: pilih
  kegiatan, isi nomor anggota, jenis, alasan, bukti (opsional); centang **Langsung setujui**.
- **Perlu Ditinjau**: scan yang perlu keputusan —
  *Konflik Offline* (mis. kartu diblokir setelah scan offline), *Override*, *Anomali* (kartu sama
  di dua tempat dalam satu menit). **Terima** atau **Batalkan**.

## Statistik & peringatan

**Beranda** menampilkan tren % hadir per kegiatan, % hadir per divisi, dan **Perlu perhatian**:
anggota aktif yang Alpa 3× berturut-turut atau kehadirannya di bawah 60%. Klik nama untuk detail.

## Rekap & ekspor

**Rekap & Ekspor**: rekap per periode (Super Admin) atau per divisi → **Tampilkan** →
**Ekspor Excel/PDF**. Ekspor diproses di latar; unduh di tab **Hasil Ekspor**. Rekap satu kegiatan
ada di halaman kegiatan → Ringkasan → Ekspor. Berkas ekspor dihapus otomatis setelah 7 hari.

## Privasi (Super Admin)

- Detail anggota → **Privasi**: catat persetujuan anggota lama, atau **Hapus data pribadi**
  (hanya untuk anggota yang tidak aktif di periode berjalan; ketik nomor anggota untuk konfirmasi).
  Rekap kehadirannya tetap utuh.
- **Pengaturan** → *Privasi*: masa simpan alasan & lampiran izin dan data anggota lama.

## Pemantauan (Super Admin)

- **Pengaturan** → *Sistem*: status database, cache, penyimpanan, queue worker, dan scheduler.
  Bila ada yang **Gagal**, hubungi pengelola server.
- **Log Aktivitas**: siapa melakukan apa (login, koreksi, blokir kartu, dll.).
