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
- **App anggota:** login `anggota@hmps.test` / `password` → tab **Kartu** → *Tampilkan QR* (QR berganti
  tiap menit; scan dari HP lain sebagai petugas), **Kegiatan** (status + Ajukan izin dengan lampiran
  gambar/PDF), **Riwayat** (persentase + daftar). Lalu setujui/tolak izinnya di dashboard → Pengajuan Izin.
- **2FA admin:** dashboard → Akun Saya → Aktifkan 2FA → scan QR dengan aplikasi authenticator → simpan
  kode cadangan. Keluar lalu masuk lagi → diminta kode. Di app: login akun itu → muncul kolom Kode 2FA.
- **Splash & sambutan (app):** buka app → logo berputar di latar hitam → layar sambutan (logo meluncur,
  teks muncul bertahap, tombol Masuk) → Masuk: logo naik & mengecil, formulir muncul. Ikon launcher baru.
- **Navigasi bawah anggota (mode gelap HP):** tiga tombol terbaca (aktif putih).
- **Statistik:** dashboard Beranda → grafik tren, per divisi, tabel Perlu perhatian. App anggota → Riwayat
  menampilkan peringatan bila Alpa 3× berturut-turut.
- **Duplikat kegiatan:** halaman kegiatan → Duplikat (coba Tiap minggu × 3).
- **Pengumuman & pengingat:** dashboard → Pengumuman → buat → muncul di app anggota (tab Kegiatan);
  kegiatan yang mulai < 24 jam muncul sebagai pengingat.
- **Rapor PDF:** dashboard → detail anggota → Rapor PDF; app anggota → Riwayat → Unduh rapor PDF.
- **Status sistem:** dashboard → Pengaturan → Sistem → semua "Normal" bila `php artisan queue:work` dan
  `php artisan schedule:work` jalan; hentikan worker > 5 menit → Queue worker "Gagal".
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

### Baru sejak APK dev.13 (belum pernah terpasang di HP)

Siapkan dulu: dashboard jalan (`php artisan serve` + `npm run build`), akun contoh dari seeder,
dan — untuk alur pendaftaran — **Pengaturan → Aplikasi → Buka pendaftaran**.

**Kartu anggota & pendaftaran (app)**
- **Kartu di halaman utama:** login `anggota@hmps.test` → tab **Kartu** menampilkan kartu ala ATM
  (logo, chip, nomor, nama, divisi, berlaku s.d.). Cek di mode terang & gelap, dan dengan ukuran huruf
  HP terbesar (kartu tetap utuh).
- **Daftar akun → e-profil:** di layar Masuk muncul **Belum punya akun? Daftar** (hanya saat
  pendaftaran dibuka) → buat akun → kartu **kosong** "Daftarkan keanggotaan dengan e-profil" → ketuk →
  isi NIM, divisi, foto, centang persetujuan → **Kirim** → kartu jadi **Menunggu pengesahan**.
  Tutup pendaftaran di dashboard → tombol Daftar hilang (buka ulang app).
- **Pengesahan:** dashboard → **Anggota → Pendaftar** (ada angka di menu) → centang → *Ubah status →
  Aktif* → di app tarik layar Kartu → kartu **aktif**, tombol *Tampilkan QR* muncul.
- **Daftarkan kartu NFC sendiri (perlu kartu NTAG215 kosong):** kartu aktif → **Daftarkan kartu NFC
  saya** → tempel kartu → "Kartu aktif" → scan kartu itu di kegiatan. Tombol hilang setelah sekali pakai.
- **Deteksi NFC (tab Kartu, kartu aktif):** di HP ber-NFC yang **menyala** → tombol *Daftarkan kartu NFC
  saya*. Matikan NFC di pengaturan HP → buka lagi tab Kartu → *NFC di HP ini sedang mati* → *Buka
  pengaturan NFC* membuka layar NFC HP → nyalakan, kembali → tombol daftarkan muncul. Di HP **tanpa NFC**
  → *HP ini tidak mendukung NFC* + *Minta pengurus terbitkan kartu*.
- **Lapor kartu:** tab Kartu → *Lapor kartu hilang/rusak* → pilih **Kartu hilang** → kartu lama langsung
  ditolak saat discan (app petugas: kartu diblokir/hilang); tab Kartu menampilkan *Laporan menunggu tindak
  lanjut*. Dashboard → **Kartu → Permintaan Pengganti** (angka di menu) → **Tindak lanjuti**:
  - *Izinkan daftar sendiri* → di app tombol daftarkan kartu muncul lagi;
  - *Pengurus terbitkan* + catatan (mis. "ambil di sekretariat Senin") → app menampilkan *Pengurus akan
    menerbitkan kartu Anda* beserta catatannya; terbitkan lewat app petugas → Terbitkan Kartu;
  - *Tolak* + catatan → app menampilkan *Laporan kartu ditolak*.
  Coba juga laporan **HP tanpa NFC** (bawaannya *Pengurus terbitkan*).
- **Cetak kartu sendiri:** tab Kartu (aktif) → *Unduh kartu (PDF) untuk dicetak* → PDF terbuka (depan &
  belakang, 85,6 × 54 mm). Portal: tombol yang sama di halaman Kartu. Kartu belum disahkan → ditolak.
- **Denda absen QR:** Pengaturan → Organisasi → **Kebijakan denda** → aktifkan, nominal mis. 5000, jatah
  gratis 1. Anggota yang **punya kartu aktif** absen pakai QR dua kali di dua kegiatan → pertama gratis,
  kedua hasil scan petugas menyebut *Denda QR Rp5.000*. Anggota tanpa kartu / kartu dilapor hilang →
  tidak didenda. Menu **Denda** → total belum lunas → centang → *Tandai lunas* / *Bebaskan*. Batalkan
  absensinya di Daftar Hadir → dendanya otomatis dibebaskan. Anggota melihat denda di **Riwayat**
  (app & portal).
- **Password sementara:** dashboard → Anggota → centang → **Buat akun app** → cetak lembar → masuk di
  app dengan password sementara → wajib **Buat password baru** sebelum bisa memakai app.
- **Lupa password (perlu SMTP diatur):** layar Masuk → *Lupa password?* → email → tautan → atur ulang.
  Tanpa SMTP: muncul pesan "hubungi pengurus".
- **Langganan jadwal:** tab Kegiatan → *Langganan jadwal ke kalender* → Salin tautan → tambahkan di
  Google Calendar (Setelan → Tambahkan kalender → Dari URL) → kegiatan muncul.
- **Ikon & hak cipta:** ikon launcher sama dengan favicon (kartu + centang); di layar sambutan ada
  tulisan kecil "© 2026 Ranupane Rimba · GPL-3.0".

**Portal web anggota (iPhone / tanpa app)**
- Buka alamat server di Safari/Chrome HP → masuk sebagai anggota → `/saya`: kartu + QR (layar tidak
  meredup), Kegiatan (ajukan izin + foto), Riwayat (rapor PDF), Akun (ganti password, langganan jadwal).
- Akun tanpa e-profil → kartu kosong → **e-profil** versi web.
- **Bagikan → Tambahkan ke Layar Utama** → ikon app di layar utama, terbuka di Kartu.
- **Notifikasi push (perlu HTTPS + kunci VAPID):** Akun → Aktifkan notifikasi → terima notifikasi uji;
  buat pengumuman di dashboard → notifikasi masuk; kegiatan ±1 jam lagi → pengingat.

**Petugas tanpa NFC: pindai lewat browser**
- Masuk web sebagai `petugas@hmps.test` → `/pindai` → pilih kegiatan yang Dibuka → izinkan kamera →
  arahkan ke QR anggota (dari app/portal HP lain) → hijau Hadir, bunyi & getar; scan lagi → kuning
  "Sudah tercatat". Kamera hanya jalan di `https://` atau `localhost`.

**Dashboard pengurus**
- **Kalender:** Kegiatan → tab Kalender → warna per divisi, tanda ⚠ Bentrok untuk dua kegiatan yang
  tumpang-tindih; di HP tampil sebagai daftar agenda.
- **Monitor langsung:** grafik Kedatangan (garis Batas telat) & Per divisi bertambah saat scan.
- **Pencarian cepat:** **Ctrl+K** → ketik nama anggota/kegiatan → Enter.
- **Tindakan massal:** Anggota → centang beberapa → Pindahkan divisi / Ubah status / Arsipkan /
  Cetak kartu / Buat akun app; Pengajuan Izin → centang → Setujui/Tolak.
- **Cetak desain kartu:** detail anggota → **Cetak kartu** (per kartu) atau massal **A4 · 10 kartu** →
  cetak di kertas/PVC, cek ukuran 85,6 × 54 mm dan kesejajaran depan–belakang saat bolak-balik.
- **Pendaftaran mandiri via tautan:** Pengaturan → Aplikasi → QR pendaftaran → isi dari HP →
  muncul di Anggota → Pendaftar.
- **Beranda:** kartu *Dibanding periode lalu* (perlu periode sebelumnya) dan grafik tren.
- **Aksesibilitas formulir:** klik label kolom → kursor pindah ke kolomnya; simpan formulir kosong →
  kursor ke kolom bergalat pertama.
- **Email & push:** Pengaturan → Sistem → kartu Email & Notifikasi push (status, cara mengaktifkan,
  *Kirim email uji*). Akun → Ringkasan mingguan → *Lihat contoh*.

## Siap diuji: tugas petugas/pengurus (belum terpasang di HP)

- Dashboard (Super Admin) → Anggota → detail anggota yang punya akun app → **Tugas** → *Petugas Scan* →
  *Beri tugas*. Di HP anggota itu: app keluar sendiri → masuk lagi → ada tab **Tugas** (beranda petugas),
  tab anggota lain tetap ada. Coba scan kartu/QR **miliknya sendiri** → merah *Kartu sendiri*.
- **Cabut tugas** (alasan wajib) → HP-nya keluar → masuk lagi → tab Tugas hilang; notifikasi "Tugas … berakhir".
- Beri tugas dengan tanggal berakhir kemarin… (tidak bisa: ditolak); beri tanggal hari ini → besok
  (scheduler `tugas:kedaluwarsa` tiap jam) akun kembali jadi anggota.
- Pengaturan → jadikan periode lain *berjalan* → tugas periode lama berakhir → **Petugas & Pengurus** →
  *Periode sebelumnya* → centang → *Perpanjang*.
- Ubah peran akun di Pengguna & Peran → HP akun itu keluar sendiri, layar Masuk menampilkan penjelasan.

## 2026-11-24 · Uji kartu NFC (laporan penguji)

Versi APK dan rincian langkah tidak dicatat.

| Yang diuji | Hasil | Status |
|---|---|---|
| Kartu NFC di app | **Berhasil** | OK |
| Scan offline | **Belum berhasil** (gejala di layar tidak dicatat) | Perbaikan di PR #70; perlu uji ulang di HP |

## Perlu diuji ulang

Ikuti [daftar uji HP](uji-hp.md): pembaruan dari APK lama, scan ditolak, ganti akun, akses berakhir.

## 2026-11-25 · Uji di HP fisik (Samsung A36, Android 17)

Build debug lokal dari `main` + #97 + #98 + #99, dipasang terpisah dari app rilis; server uji lewat kabel USB.

| Yang diuji | Hasil | Status |
|---|---|---|
| Tetap masuk setelah dipaksa berhenti | Ya | OK |
| NFC dibaca di mode pesawat | Ya | OK |
| Scan offline → antre → tersinkron sendiri (30 detik) | Tepat satu baris di server | OK |
| Jam scan (zona waktu) | Benar, WIB | OK |
| Notifikasi: izin diminta sendiri, muncul, diketuk | Berhasil | OK |
| Notifikasi dari jadwal asli, proses app mati, layar menyala | Muncul 15.19 | OK |
| Notifikasi saat layar terkunci | Belum terbukti (dua uji paksa gagal, tidak sah) | Uji ulang |
| HMAC kartu | Server uji dipatch agar mengenali kartu | Belum diuji |
| NFC pulih setelah dimatikan, sertifikat di app, scan QR tamu, fitur 4/5/7 di app | – | Belum diuji |
