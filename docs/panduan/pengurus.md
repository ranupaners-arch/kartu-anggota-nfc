# Panduan Pengurus (Super Admin & Admin Divisi)

Dashboard dibuka di browser pada alamat server organisasi. Menu yang tampil menyesuaikan peran.
Bagian bertanda **(Super Admin)** hanya tersedia untuk Super Admin.

## Keamanan akun

- **Akun Saya** → ganti password, dan aktifkan **Keamanan (2FA)**: pindai QR dengan aplikasi
  authenticator (Google Authenticator, 2FAS, dll.), masukkan kode 6 digit, lalu **simpan 8 kode
  cadangan** di tempat aman. Sangat disarankan untuk semua pengurus.
- Lupa HP & kode cadangan? Super Admin lain bisa **Reset 2FA** di Pengguna & Peran.

## Pencarian cepat

- Tekan **Ctrl+K** (Mac: **⌘K**) atau tombol **Cari…** di kanan atas, di halaman mana pun.
- Ketik nama halaman, nama/nomor anggota, atau judul/lokasi kegiatan; **↑/↓** memilih, **Enter**
  membuka, **Esc** menutup. Admin Divisi hanya menemukan anggota & kegiatan divisinya.

## Jadwal di kalender

- **Akun** (menu kanan atas) → **Langganan jadwal** → **Buat tautan langganan** → **Salin**, lalu
  tambahkan di Google Calendar (*Setelan → Tambahkan kalender → Dari URL*). Isinya kegiatan yang
  bisa Anda kelola, periode berjalan. **Ganti tautan** bila tersebar.

## Email: lupa password & ringkasan mingguan

Aktif setelah email server diatur (lihat *Pengaturan → Sistem → Email*; cara mengatur di
`docs/deploy.md`).
- **Lupa password?** di halaman masuk (dashboard) dan di app: tautan atur ulang dikirim ke email.
  Sebelum aktif, reset password lewat **Pengguna & Peran → Reset password**.
- **Ringkasan mingguan** tiap Senin 07.00 ke email pengurus: kegiatan 7 hari terakhir (% hadir),
  izin & tinjauan yang menunggu, anggota yang perlu perhatian, kegiatan pekan ini (Admin Divisi:
  divisinya saja). Atur di **Akun → Ringkasan mingguan** (ada **Lihat contoh**).

## Pemberitahuan alur kartu

Super Admin otomatis diberi tahu saat ada **pendaftar baru** (e-profil/formulir pendaftaran) dan
**laporan kartu** baru (hilang/rusak/HP tanpa NFC) — lewat **email** (bila SMTP diatur) dan **push**
(aktifkan di **Akun → Notifikasi**, bila server mengaktifkan push). Anggota diberi tahu saat
e-profilnya **disahkan**, saat laporan kartunya **diputuskan**, dan saat **perubahan data**-nya
disetujui/ditolak (beserta catatan Anda). Super Admin juga diberi tahu saat ada pengajuan perubahan data.

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
- **Pendaftaran mandiri**: **Pengaturan → Aplikasi → Pendaftaran anggota mandiri → Buka
  pendaftaran**, lalu bagikan tautan/QR-nya (mis. di grup atau poster open recruitment). Calon anggota
  mengisi nama, NIM, email, HP, angkatan, divisi minat, foto, dan persetujuan data. Mereka muncul di
  **Anggota → Pendaftar** (angka di menu Anggota). Setujui: centang → **Ubah status → Aktif**;
  tolak: **Arsipkan**. **Tutup pendaftaran** atau **Ganti tautan** bila tautan tersebar ke luar.
  Selama pendaftaran dibuka, calon anggota juga bisa **membuat akun sendiri di app/portal** lalu
  mengisi **e-profil** dari kartu kosong; mereka masuk ke antrean **Pendaftar** yang sama. Setelah
  disahkan (status Aktif), kartu di app/portal mereka aktif otomatis. Pengesahan hanya oleh Super Admin.
- **Tindakan massal** (Super Admin): centang beberapa anggota (atau **Pilih semua** di halaman
  itu) → bilah di bawah layar: **Pindahkan** ke divisi lain, **Ubah status** (mis. jadikan Alumni
  di akhir periode), atau **Arsipkan**. Selalu ada konfirmasi dulu.

### Kartu
- Kartu diterbitkan lewat **app** (login Super Admin): Beranda → menu **Terbitkan Kartu** → cari anggota →
  **Terbitkan & tulis kartu** → tempel kartu NTAG215 kosong. Kartu lama anggota itu otomatis diblokir.
- **Cetak desain kartu (PDF)**: detail anggota → **Cetak kartu**, atau centang banyak anggota →
  **Cetak kartu** di bilah bawah. Format **A4 · 10 kartu** (garis potong; halaman belakang kolomnya
  ditukar — cetak bolak-balik *balik di sisi panjang*) atau **Per kartu (PVC)** untuk printer kartu.
  Ukuran CR80 (85,6 × 54 mm); foto, nama, nomor, divisi, berlaku s.d. akhir periode.
- Kartu hilang/dicuri: dashboard → **Kartu** → **Hilang** atau **Blokir**. Berlaku seketika.
- **Kartu dari anggota sendiri**: anggota yang sudah disahkan bisa mendaftarkan kartu NFC kosong lewat
  app-nya **satu kali**. Laporan anggota (*kartu hilang*, *kartu rusak*, *HP tanpa NFC*) muncul di
  **Kartu → Permintaan Pengganti** (angka di menu Kartu) → **Tindak lanjuti**: *Izinkan daftar sendiri*
  (jatah +1) atau *Pengurus terbitkan* (tulis kartu lewat app petugas → Terbitkan Kartu, lalu serahkan),
  dengan catatan untuk anggota (mis. tempat & waktu pengambilan); atau *Tolak*. Laporan *hilang* sudah
  otomatis memblokir kartu lamanya.
- **Perubahan data diri**: anggota bisa mengajukan perubahan **nama, no. HP, atau foto** dari app/portal.
  Pengajuan muncul di **Perubahan Data** (angka di menu) dengan perbandingan lama → baru (termasuk foto
  lama vs baru) → **Tinjau** → *Setujui & terapkan* (data langsung diperbarui) atau *Tolak* (wajib menulis
  alasan; data lama tetap dipakai). NIM, divisi, dan status tetap hanya diubah pengurus lewat Anggota.
- **Riwayat kartu per anggota**: detail anggota → tab **Kartu** — semua kartu (siapa yang menerbitkan:
  pengurus atau *anggota sendiri*), laporan kartu & keputusannya, sisa jatah daftar sendiri, dan
  **Tambah jatah** (maks. 3) bila perlu memberi kesempatan tanpa laporan.

### Petugas & Pengurus (tugas per periode)
- Petugas Scan & Admin Divisi adalah **anggota yang diberi tugas**, satu akun per orang. Beri tugas di
  **Anggota → detail → Tugas** (Petugas Scan, atau Admin Divisi + divisinya; tanggal berakhir opsional —
  kosong = akhir periode). Anggota harus sudah punya **akun app** (Anggota → Buat akun app).
- **Cabut tugas** (mengundurkan diri / tidak bertugas lagi) di bagian yang sama atau di **Petugas &
  Pengurus** → *Cabut tugas* + alasan. Akun langsung kembali menjadi anggota biasa, dikeluarkan dari
  HP-nya, dan diberi tahu. Data kehadiran & kartunya tetap.
- Tugas **berakhir otomatis** pada tanggal berakhir atau saat **periode berjalan diganti**. Setelah ganti
  periode, **Petugas & Pengurus → Periode sebelumnya** → centang yang tetap bertugas → *Perpanjang*.
- Petugas tidak bisa memindai kartu/QR miliknya sendiri (mencegah titip absen).
- Akun **bersama** tanpa data anggota (mis. HP sekretariat) tetap diatur di Pengguna & Peran.

### Akun petugas & perangkat
- **Pengguna & Peran** → **Tambah Pengguna** → peran *Petugas Scan* (atau *Anggota*, tautkan ke
  nomor anggota). Password sementara tampil sekali dan **wajib diganti** pemiliknya saat pertama
  masuk (app & dashboard); begitu juga setelah **Reset password**.
- **Mengubah peran/divisi** akun (mis. petugas berhenti bertugas → *Anggota*) langsung
  **mengeluarkan akun itu dari semua HP**; saat masuk lagi, app tampil sesuai peran barunya.
- **Akun app anggota sekaligus**: **Anggota** → centang anggota (atau *Pilih semua*) → **Buat akun
  app**. Login memakai email anggota; yang belum punya email atau sudah punya akun dilewati
  beserta alasannya. Halaman berikutnya berisi **lembar akun** (email + password sementara) —
  **Cetak lembar** / simpan sebagai PDF atau **Salin tabel** saat itu juga, karena hanya tampil
  sekali. Tiap lembar memuat **QR penghubung** — anggota memindainya di app, lalu masuk.
  Kolom **Akun app** dan filter **Kesiapan** (*Belum punya akun app* / *Belum punya kartu*) di
  daftar anggota membantu memantau siapa yang belum siap.
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

- **RSVP** (formulir kegiatan → *Buka RSVP*): batas waktu dan kuota opsional. Jawaban ada di halaman kegiatan →
  tab **RSVP**. Kuota hanya untuk kegiatan yang tidak wajib hadir; tempat kosong diisi otomatis dari daftar tunggu,
  dan menurunkan kuota tidak mengeluarkan yang sudah terdaftar. RSVP tidak memengaruhi scan atau Alpa.
- **Duplikat** (halaman kegiatan): salin kegiatan ke tanggal baru — target, sesi, dan jendela
  check-in ikut. Bisa **berulang** tiap minggu / 2 minggu / bulan (maks. 12), cocok untuk rapat rutin.

### Kalender kegiatan
- **Kegiatan** → tab **Kalender**: semua kegiatan sebulan, warna per divisi (abu = kegiatan umum),
  tanggal hari ini ditandai. Tanda ⚠ **Bentrok** muncul bila dua kegiatan di hari yang sama
  waktunya tumpang-tindih — cek dulu sebelum membuat kegiatan baru. Di HP tampil sebagai daftar
  agenda per hari. Filter divisi/tipe/cari tetap berlaku.

## Pengumuman

**Pengumuman** → **Pengumuman Baru**: judul, isi, untuk semua anggota atau satu divisi, opsional
disematkan dan diberi tanggal kedaluwarsa. Tampil di app anggota (tab Kegiatan). Admin Divisi hanya
untuk divisinya.

- Pengumuman baru juga dikirim sebagai **notifikasi push** ke HP anggota yang mengaktifkannya di
  portal (bila server sudah diatur — *Pengaturan → Sistem*). Anggota juga mendapat pengingat ±1 jam
  sebelum kegiatan bila belum tercatat hadir/izin.

## Selama kegiatan

- **Monitor langsung** (tombol di halaman kegiatan, buka di laptop/proyektor): jumlah hadir,
  scan terbaru dengan foto, dan daftar yang belum datang; diperbarui tiap 4 detik.
  Grafik **Kedatangan** menunjukkan jumlah scan per beberapa menit sejak check-in dibuka; batang di
  kanan garis putus-putus *Batas telat* berarti datang telat. **Per divisi** menunjukkan berapa
  yang sudah hadir dari target tiap divisi (terendah di atas).
- **Daftar Hadir**: koreksi status (Hadir/Telat/Izin/Sakit/Alpa) atau **Batalkan** scan yang salah —
  wajib alasan; riwayat koreksi tersimpan.

## Setelah kegiatan

- **Tutup Kegiatan**: anggota wajib yang tidak scan ditandai **Alpa**, atau **Izin/Sakit** bila
  izinnya sudah disetujui. Bila tidak ditutup manual, sistem menutup otomatis ±5 menit setelah
  check-in sesi terakhir berakhir.
- **Pengajuan Izin**: **Setujui** / **Tolak** (lihat lampiran bukti). Bila disetujui setelah
  kegiatan ditutup, status Alpa anggota itu otomatis diganti Izin/Sakit (tercatat sebagai koreksi).
  Banyak pengajuan sekaligus: centang di tab *Menunggu* → **Setujui** / **Tolak** di bilah bawah
  (catatan opsional berlaku untuk semuanya).
- Anggota mengabarkan izin langsung (tanpa app)? **Pengajuan Izin** → **Catat Izin**: pilih
  kegiatan, isi nomor anggota, jenis, alasan, bukti (opsional); centang **Langsung setujui**.
- **Perlu Ditinjau**: scan yang perlu keputusan —
  *Konflik Offline* (mis. kartu diblokir setelah scan offline), *Akses Berakhir* (scan dari petugas
  yang perangkatnya dicabut, perannya diubah, atau akunnya dinonaktifkan; dikirim paling lambat 7 hari; belum tercatat sampai diterima), *Override*, *Anomali* (kartu sama
  di dua tempat dalam satu menit). **Terima** atau **Batalkan**.

## Notulen & dokumen

Halaman kegiatan → tab **Notulen & Dokumen**.
- Notulen disimpan sebagai **draf**, lalu **Terbitkan** supaya peserta bisa membaca dan mendapat notifikasi. Bisa ditarik lagi.
- Bila dua pengurus menulis bersamaan, yang kedua ditolak dan tulisannya tetap ada di layar untuk digabungkan.
- Dokumen (PDF, gambar, Office; maks. 5 MB, 20 berkas) dibuka untuk **peserta kegiatan** atau **hanya pengurus**.
- Kegiatan yang punya notulen atau dokumen tidak bisa dihapus.

## Sertifikat kegiatan

Halaman kegiatan yang sudah **ditutup** → tab **Sertifikat**: isi judul, peran, penanda tangan, lalu **Terbitkan**. Yang
mendapat: status Hadir/Telat (kegiatan multi-sesi: di jumlah sesi minimal).
- Absensi dikoreksi setelah terbit → muncul pemberitahuan; **Terbitkan ulang** menambah atau mencabut sesuai absensi.
- **Cabut** perorangan wajib beralasan dan tidak pulih oleh terbit ulang; gunakan **Pulihkan**.
- QR di sertifikat membuka halaman cek keaslian (tanpa login). Sertifikat yang dicabut tampil tidak berlaku, tanpa nama.

## Iuran

- **Akses:** Super Admin memberi **Bendahara** (mencatat) atau **Sekretaris** (audit, hanya melihat) di **Pengguna &
  Peran → Ubah → Akses keuangan**. Ini tambahan di luar peran dan berakhir otomatis saat periode berganti. Mereka
  membuka `/iuran` di browser (anggota: portal → Akun → *Kelola iuran*).
- **2FA wajib** bagi bendahara & sekretaris: saat pertama membuka `/iuran` mereka diminta mengaktifkannya (aplikasi
  authenticator), lalu menyimpan kode cadangan. HP hilang → Super Admin: **Pengguna & Peran → Reset 2FA**.
- **Buat tagihan:** judul, nominal (sama untuk semua anggota aktif), jatuh tempo opsional. Anggota yang disahkan
  kemudian: **Tambah anggota baru**.
- **Lunas / Bebaskan / Belum lunas:** centang anggota → isi catatan. Membebaskan atau membatalkan tanda wajib beralasan. Yang sudah
  lunas harus dikembalikan dulu sebelum dibebaskan.
  Bendahara tidak bisa mencatat iurannya sendiri.
- **Riwayat Perubahan** mencatat siapa, kapan, dan alasannya; tidak bisa diubah dari aplikasi. **CSV** untuk arsip.
- Tagihan salah: **Batalkan** (hanya bila belum ada yang lunas), lalu buat lagi.

## Statistik & peringatan

**Beranda** menampilkan tren % hadir per kegiatan, % hadir per divisi, dan **Perlu perhatian**:
anggota aktif yang Alpa 3× berturut-turut atau kehadirannya di bawah 60%. Klik nama untuk detail.

- **Dibanding periode lalu** (Beranda, muncul bila sudah ada periode sebelumnya): % hadir
  keseluruhan dan per divisi periode ini dibanding periode lalu. Batang = periode ini, garis tegak
  = periode lalu; selisihnya ditulis dalam *poin* (▲ naik, ▼ turun).

## Denda absen pakai QR

- **Pengaturan → Organisasi → Kebijakan denda**: aktif/nonaktif, nominal per kejadian, jatah QR gratis
  per periode.
- Denda tercatat **otomatis** saat anggota yang **punya kartu NFC aktif** absen memakai QR (kartu tidak
  dibawa); petugas melihat keterangan *Denda QR Rp…* di hasil scan. Tidak didenda: anggota yang belum
  punya kartu, atau yang kartunya sudah dilapor **hilang** (kartu langsung diblokir).
- Menu **Denda**: total belum lunas & sudah lunas periode ini; centang → **Tandai lunas** /
  **Bebaskan** / kembalikan ke belum lunas. Absensi yang dibatalkan/dikoreksi → dendanya otomatis
  dibebaskan. Anggota melihat dendanya di **Riwayat** (app & portal).
- **Rekap & Ekspor** (rekap periode/divisi) menampilkan kolom **Denda QR** dan **Belum lunas** per anggota
  serta total belum lunas; ekspor Excel/PDF ikut memuat *Denda QR (kali)*, *Belum lunas (Rp)*, *Lunas (Rp)*.

## Rapor kehadiran

Detail anggota → **Rapor PDF** (periode berjalan) atau tautan **rapor** di tiap periode pada tabel
keanggotaan: identitas, ringkasan Hadir/Telat/Izin/Sakit/Alpa, % kehadiran, dan daftar kegiatan.

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
