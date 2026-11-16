# PRD: Kartu Anggota NFC

| | |
|---|---|
| **Status** | Draft v1.1 |
| **Tanggal** | 2026-11-16 |
| **Pemilik** | Ranu |
| **Stack** | Flutter (mobile), Laravel 13 (API + dashboard Blade), PostgreSQL, Redis |

---

## 1. Ringkasan

Sistem kartu anggota berbasis NFC (NTAG215) untuk absensi kegiatan, pengelolaan keaktifan, dan administrasi anggota. Sistem ini generik untuk organisasi apa pun (himpunan mahasiswa, UKM, karang taruna, komunitas, dll.); **HMPS menjadi organisasi contoh dan pengguna pertama**. Data tiap organisasi terisolasi di server. Kartu hanya menyimpan ID bertanda tangan; seluruh data pribadi ada di server. Petugas memindai kartu lewat aplikasi mobile, pengurus mengelola lewat dashboard web.

## 2. Latar belakang dan masalah

Absensi dan pendataan keaktifan di banyak organisasi (termasuk organisasi kampus) umumnya manual (tanda tangan kertas, Google Form, spreadsheet). Akibatnya:

- Rekap lambat dan rawan salah, terutama saat pergantian pengurus.
- Titip absen mudah terjadi.
- Tidak ada data keaktifan yang bisa dipakai untuk evaluasi anggota.
- Izin/sakit dicatat tidak konsisten.

## 3. Tujuan dan metrik keberhasilan

| Tujuan | Metrik | Target (1 periode kepengurusan) |
|---|---|---|
| Absensi cepat | Waktu scan per anggota | < 3 detik, antrean 50 orang < 5 menit |
| Rekap otomatis | Waktu menyiapkan rekap setelah acara | < 1 menit (dari ±1 jam) |
| Data akurat | Selisih rekap sistem vs. pengamatan panitia (sampel 3 acara) | < 2% |
| Adopsi | Anggota aktif yang kartunya terbit | ≥ 90% |
| Dipakai nyata | Kegiatan yang memakai sistem | ≥ 10 kegiatan |
| Keandalan | Scan berhasil tersimpan (termasuk offline sync) | ≥ 99% |

## 4. Non-goals (sengaja tidak dikerjakan)

- Membaca data e-KTP atau data biometrik apa pun.
- Pembayaran digital.
- Kartu digital berbasis NFC di Apple Wallet (butuh entitlement Apple).
- Aplikasi iOS pada MVP (Flutter memungkinkan nanti, tidak diprioritaskan).
- Pendaftaran organisasi mandiri (self-service) dan pengelola lintas organisasi. Isolasi data per organisasi sudah ditegakkan di API; organisasi baru dibuat oleh pengelola sistem.

## 5. Pengguna

| Peran | Kebutuhan utama |
|---|---|
| **Super Admin** (Ketua/Sekretaris organisasi) | Kelola anggota, kartu, periode, role; lihat semua rekap |
| **Admin Divisi / Panitia** | Buat kegiatan, lihat rekap, setujui izin |
| **Petugas Scan** | Scan cepat, jelas hasilnya, tetap jalan saat internet buruk |
| **Anggota** | Lihat riwayat hadir, ajukan izin, punya QR cadangan |

## 6. Lingkup

### 6.1 MVP (Fase 1)

| ID | Fitur |
|---|---|
| F1 | Autentikasi dan role (web + mobile), 2FA opsional untuk admin |
| F2 | Manajemen anggota: CRUD, impor CSV, foto, keanggotaan per periode |
| F3 | Manajemen kartu: inisialisasi, tautkan ke anggota, blokir, ganti kartu |
| F4 | Kegiatan: jadwal, jendela check-in, target peserta, sesi (opsional) |
| F5 | Absensi tap NFC dengan status Hadir/Telat/Izin/Sakit/Alpa |
| F6 | Alur izin: pengajuan, bukti, approval |
| F7 | QR fallback per anggota |
| F8 | Koreksi dan undo scan dengan alasan, tercatat di audit log |
| F9 | Mode offline di app petugas, sinkron batch idempoten |
| F10 | Registrasi dan pencabutan perangkat petugas |
| F11 | Rekap per kegiatan, per anggota, per periode; ekspor Excel/PDF |
| F12 | Audit log aksi admin |

### 6.2 Fase 2
Poin keaktifan dan leaderboard, iuran/kas, inventaris dan peminjaman, notifikasi (FCM/email), aplikasi anggota lengkap (profil, kartu digital QR), pengumuman, RSVP dan kuota, badge dan reward.

### 6.3 Fase 3
Reader tetap ESP32 + PN532, tiket event dengan peserta eksternal, portal mitra dan diskon, dashboard analitik, SSO (misalnya Google kampus), webhook, pendaftaran organisasi mandiri.

## 7. Aturan bisnis

### 7.1 Status absensi

Waktu acuan: waktu scan di perangkat (`scanned_at`), divalidasi server terhadap toleransi drift (maks. 10 menit dari waktu server untuk scan online).

| Kondisi | Status |
|---|---|
| Scan antara `checkin_opens_at` dan `late_after` | **Hadir** |
| Scan setelah `late_after` sampai `checkin_closes_at` | **Telat** |
| Scan di luar jendela check-in | Ditolak; petugas boleh override dengan alasan (tercatat) |
| Ada izin disetujui dan tidak scan | **Izin** atau **Sakit** |
| Wajib hadir, tidak scan, tanpa izin disetujui, setelah event ditutup | **Alpa** (otomatis) |
| Izin disetujui tetapi anggota tetap scan | Status mengikuti scan (Hadir/Telat); izin ditandai tidak terpakai |

### 7.2 Kartu

- Satu anggota aktif hanya boleh punya satu kartu aktif. Menerbitkan kartu baru otomatis memblokir kartu lama.
- Status kartu: `unassigned`, `active`, `blocked`, `lost`.
- Kartu `blocked`/`lost` selalu ditolak saat scan dan dicatat sebagai percobaan.

### 7.3 Duplikat dan anomali

- Satu anggota hanya punya satu absensi per sesi (unique `session_id, member_id`); scan kedua mengembalikan `duplicate` tanpa error.
- Kartu yang sama di-tap di dua event/perangkat berbeda dalam < 60 detik ditandai anomali di log.

### 7.4 Periode kepengurusan

Semua rekap dan (fase 2) poin dihitung per periode, dan selalu dalam lingkup satu organisasi. Pergantian periode tidak menghapus atau mengubah data periode lama.

### 7.5 Peran dan hak akses

| Aksi | Super Admin | Admin Divisi | Petugas | Anggota |
|---|---|---|---|---|
| Kelola anggota dan kartu | ✔ | - | - | - |
| Buat/ubah kegiatan | ✔ | ✔ (divisinya) | - | - |
| Scan absensi | ✔ | ✔ | ✔ | - |
| Koreksi/undo absensi | ✔ | ✔ (dengan alasan) | - | - |
| Setujui izin | ✔ | ✔ (divisinya) | - | - |
| Lihat rekap | Semua | Divisinya | Event yang ditugaskan | Diri sendiri |
| Cabut perangkat, kelola role | ✔ | - | - | - |

## 8. User stories dan acceptance criteria

### US-1 Menerbitkan kartu
*Sebagai Super Admin, saya ingin menerbitkan kartu NFC untuk anggota agar anggota bisa absen dengan tap.*

- Admin memilih anggota di dashboard, lalu membuka app mode "Issue".
- App meminta payload bertanda tangan dari server, menulis ke kartu, dan mengunci kartu.
- Setelah sukses, kartu berstatus `active` dan terhubung ke anggota; kartu lama anggota tersebut (jika ada) menjadi `blocked`.
- Jika penulisan gagal di tengah jalan, kartu tidak tercatat aktif dan proses bisa diulang.
- Aksi tercatat di audit log.

### US-2 Scan absensi
*Sebagai Petugas, saya ingin memindai kartu anggota dengan cepat dan melihat hasilnya jelas.*

- Petugas memilih kegiatan yang sedang berlangsung lalu mulai scan.
- Tap kartu valid menampilkan nama, foto, divisi, dan status (Hadir/Telat) dalam < 1 detik pada jaringan normal, disertai getar/bunyi.
- Kartu tidak valid (HMAC salah), diblokir, atau bukan anggota target menampilkan alasan yang jelas dan tidak mencatat absensi.
- Scan kedua kartu yang sama menampilkan "sudah absen" tanpa membuat data ganda.
- Peringatan tambahan (misalnya iuran belum lunas, fase 2) tampil tanpa menghambat absensi.

### US-3 Bekerja offline
*Sebagai Petugas, saya ingin tetap bisa scan saat internet mati.*

- Tanpa koneksi, scan tetap menampilkan hasil berdasarkan data anggota/event yang di-cache sebelumnya.
- Scan disimpan di antrean lokal dengan `device_id` dan `scanned_at`.
- Saat online, antrean dikirim batch; server memproses idempoten (kirim ulang tidak menghasilkan duplikat).
- Konflik (misalnya kartu diblokir saat offline) ditandai untuk ditinjau admin, bukan dibuang diam-diam.
- Indikator jelas menunjukkan jumlah scan yang belum tersinkron.

### US-4 QR fallback
*Sebagai Anggota, saya ingin absen dengan QR saat kartu hilang atau lupa dibawa.*

- Anggota membuka app dan menampilkan QR pribadi yang berputar berkala.
- Petugas memindai QR lewat kamera; hasil diproses lewat pipeline yang sama dengan `source=qr`.
- QR kedaluwarsa atau sudah dirotasi ditolak.

### US-5 Mengajukan izin
*Sebagai Anggota, saya ingin mengajukan izin/sakit sebelum kegiatan.*

- Anggota memilih kegiatan, jenis (izin/sakit), alasan, dan opsional unggah bukti (gambar/PDF, maks. 5 MB).
- Admin menerima pengajuan, lalu menyetujui atau menolak dengan catatan.
- Izin yang disetujui mengubah status menjadi Izin/Sakit dan tidak dihitung Alpa.
- Pengajuan setelah event ditutup hanya bisa dilakukan admin (retroaktif) dan tercatat di audit log.

### US-6 Koreksi absensi
*Sebagai Admin, saya ingin memperbaiki absensi yang salah.*

- Admin dapat membatalkan (void) scan atau mengubah status, wajib mengisi alasan.
- Data asli tidak dihapus; perubahan tersimpan sebagai riwayat (siapa, kapan, dari-ke, alasan).
- Rekap langsung mencerminkan koreksi.

### US-7 Membuat kegiatan
*Sebagai Admin, saya ingin membuat kegiatan beserta aturan absensinya.*

- Field wajib: judul, tipe, waktu, lokasi, jendela check-in (`opens_at`, `late_after`, `closes_at`), target peserta (semua, divisi, atau daftar anggota), wajib/tidak.
- Kegiatan multi-sesi memungkinkan absensi terpisah per sesi.
- Kegiatan yang sudah berjalan tidak bisa diubah jendela waktunya tanpa alasan tercatat.
- Saat event ditutup, sistem menjalankan penandaan Alpa otomatis.

### US-8 Rekap dan ekspor
*Sebagai Admin, saya ingin rekap yang bisa langsung dipakai.*

- Rekap per kegiatan: daftar hadir, jumlah per status, persentase.
- Rekap per anggota: riwayat kehadiran dan persentase per periode.
- Rekap per divisi dan periode.
- Ekspor ke Excel dan PDF; hasil ekspor besar diproses di queue dan bisa diunduh setelah siap.

### US-9 Kartu hilang
*Sebagai Super Admin, saya ingin memblokir kartu hilang dan menerbitkan penggantinya.*

- Kartu dapat diblokir dari dashboard dalam satu aksi; efek berlaku seketika (online) dan setelah sinkron cache (offline).
- Percobaan scan kartu terblokir tercatat dengan waktu dan perangkat.
- Penerbitan kartu pengganti mengikuti US-1.

### US-10 Mengelola perangkat petugas
*Sebagai Super Admin, saya ingin mencabut akses HP petugas yang hilang.*

- Setiap login mobile mendaftarkan perangkat; admin melihat daftar perangkat dan waktu terakhir aktif.
- Pencabutan perangkat membatalkan token; request berikutnya dari perangkat itu ditolak.
- Scan offline dari perangkat yang dicabut ditahan untuk tinjauan, tidak otomatis diterima.

### US-11 Mengelola anggota
*Sebagai Super Admin, saya ingin mengelola data anggota per periode.*

- Impor CSV (nomor anggota, nama, email, angkatan, divisi) dengan pratinjau dan laporan baris error sebelum commit.
- Keanggotaan per periode punya status: applicant, active, inactive, alumni.
- Anggota alumni tidak masuk target kegiatan baru secara default.
- Data anggota yang dihapus bersifat soft delete.

## 9. Model kartu dan keamanan

**Payload NDEF:** `<prefix>:v1:<key_id>:<card_public_id>:<hmac>` (prefix default `card`, diatur lewat `CARD_PAYLOAD_PREFIX`; tidak boleh diubah setelah kartu pertama ditulis). QR anggota: `<prefix>:qr:<token>`.

- `card_public_id`: ULID acak, bukan nomor anggota.
- `hmac`: HMAC-SHA256 atas `key_id + card_public_id` dengan secret server, dipotong 12 byte; `key_id` mendukung rotasi kunci.
- Kartu di-lock (read-only) dan dilindungi password NTAG saat inisialisasi.
- UID hardware disimpan dalam bentuk hash sebagai sinyal anti-clone tambahan (bukan jaminan).
- Token API per perangkat (Sanctum), rate limit pada endpoint scan, HTTPS wajib, secret di environment.
- Data minimal di server: nomor anggota, nama, email, foto, divisi. Persetujuan dikumpulkan saat pendaftaran; anggota alumni dapat meminta penghapusan (UU PDP).

**Risiko yang diterima:** NTAG dapat di-clone. Mitigasi: petugas hadir saat scan, tampilan foto untuk verifikasi visual, deteksi anomali di log. Tidak digunakan untuk transaksi bernilai tinggi.

## 10. Arsitektur

```
Kartu NTAG ─tap─▶ Flutter App ──HTTPS/JSON──▶ Laravel (API + Blade) ─▶ PostgreSQL
Browser (Dashboard) ──────────────────────────▶         │            ▶ Redis (queue/cache)
ESP32+PN532 (fase 3) ─────────────────────────▶         │            ▶ Storage (foto/bukti/ekspor)
```

- Satu aplikasi Laravel: `routes/api.php` untuk mobile, `routes/web.php` untuk dashboard; logika bersama di Service/Action class.
- Node/Express tidak dipakai; realtime (jika perlu) via Laravel Reverb.
- Deploy: satu VPS dengan Docker Compose (Nginx, PHP-FPM, PostgreSQL, Redis), CI dari GitHub Actions.

## 11. Model data (inti)

```
organizations(id, name, slug)
terms(id, organization_id, name, starts_on, ends_on, is_current)
divisions(id, organization_id, name)
members(id, organization_id, member_no, name, email, phone, photo_path, deleted_at)
memberships(member_id, term_id, division_id, role, status)
cards(id, organization_id, public_id, key_id, hw_uid_hash, member_id, status, issued_at, revoked_at)
member_qr(member_id, token, rotated_at)
events(id, organization_id, term_id, title, type, location, starts_at, ends_at,
       checkin_opens_at, late_after, checkin_closes_at, is_mandatory, closed_at, created_by)
event_sessions(id, event_id, title, starts_at, ends_at)
event_targets(event_id, division_id | member_id)
attendances(id, event_id, session_id, member_id, card_id, status, source,
            scanned_by, device_id, scanned_at, synced_at, voided_at)
            UNIQUE(session_id, member_id)
attendance_changes(id, attendance_id, changed_by, from_status, to_status, reason, created_at)
excuse_requests(id, event_id, member_id, type, reason, attachment_path,
                status, reviewed_by, reviewed_at)
users(id, member_id, email, password, role, two_factor_secret)
devices(id, user_id, name, token_id, last_seen_at, revoked_at)
audit_logs(id, user_id, action, entity, entity_id, payload, ip, created_at)
```

Semua tabel utama membawa `organization_id` atau terhubung ke tabel yang membawanya. API menegakkan isolasi lewat global scope: data organisasi lain diperlakukan tidak ada (404).

## 12. API (ringkas)

```
POST /api/auth/login               POST /api/auth/logout
GET  /api/me                       GET  /api/devices (admin)
POST /api/devices/{id}/revoke

GET  /api/events?status=ongoing    GET  /api/events/{id}/roster   (cache offline)
POST /api/events/{id}/scan         { payload, device_id, scanned_at, source }
POST /api/events/{id}/scan/batch   (sinkron offline, idempoten)

POST /api/cards/init               POST /api/cards/{id}/assign
POST /api/cards/{id}/block

POST /api/excuses                  PATCH /api/excuses/{id}  (approve/reject)
PATCH /api/attendances/{id}        { status|void, reason }

GET  /api/members/{id}/summary     GET  /api/me/qr
```

Respons scan: `status` (`ok`, `duplicate`, `blocked`, `invalid`, `not_target`, `outside_window`), data anggota, dan `attendance_status`.

## 13. Persyaratan non-fungsional

| Aspek | Persyaratan |
|---|---|
| Performa | Scan < 1 detik (p95) pada jaringan normal; batch 200 scan < 5 detik |
| Ketersediaan | Backup database harian, uji restore tiap kuartal; mode offline di app |
| Keamanan | HTTPS, token per perangkat, rate limit, audit log, 2FA admin, soft delete |
| Privasi | Data minimal, consent, hak hapus alumni, retensi ditetapkan per periode |
| Kompatibilitas | Android 8+ dengan NFC; dashboard di browser modern; layout responsif |
| Aksesibilitas | Kontras memadai, ukuran sentuh ≥ 44 px, umpan balik hasil scan visual + getar + suara |
| Observabilitas | Sentry untuk error, log terstruktur, health check |
| Lokalisasi | Bahasa Indonesia; zona waktu Asia/Jakarta |

## 14. Risiko dan mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Kartu di-clone | Titip absen | HMAC, lock kartu, verifikasi foto, deteksi anomali |
| Internet buruk di lokasi | Scan gagal | Mode offline + sinkron idempoten |
| HP petugas tanpa NFC/rusak | Scan terhenti | Daftar perangkat cadangan; QR scan via kamera |
| Kartu hilang | Penyalahgunaan | Blokir instan, penerbitan ulang cepat |
| Pergantian pengurus | Pengetahuan hilang | Role-based access, dokumentasi, seed data, data per periode |
| Scope membengkak | Proyek tidak selesai | MVP dibatasi F1-F12, fase 2 dipilih dari umpan balik nyata |
| Adopsi rendah | Sistem tidak dipakai | Uji di satu acara nyata, libatkan pengurus sejak awal |

## 15. Rencana rilis

| Milestone | Isi | Perkiraan |
|---|---|---|
| M0 | Prototipe baca/tulis NTAG, skema DB, kerangka Laravel + Flutter | Minggu 1-2 |
| M1 | F1-F4: auth, anggota, kartu, kegiatan | Minggu 3-5 |
| M2 | F5, F7, F9: scan, QR, offline | Minggu 6-8 |
| M3 | F6, F8, F10-F12: izin, koreksi, perangkat, rekap, audit | Minggu 9-10 |
| M4 | Uji lapangan di satu acara nyata, perbaikan, dokumentasi | Minggu 11-12 |
| Rilis | Dipakai pengurus untuk satu periode | Setelah M4 |

Estimasi untuk satu pengembang paruh waktu; sesuaikan setelah M0.

## 16. Anggaran awal (skenario hemat)

| Item | Estimasi |
|---|---|
| 60 kartu NTAG215 (termasuk cadangan) | Rp 300.000-500.000 |
| Cetak desain kartu | Rp 150.000-350.000 |
| Reader | Rp 0 (HP Android) |
| Hosting/VPS dan domain | Rp 0-50.000/bulan |
| **Total awal** | **± Rp 450.000-900.000** |

## 17. Pertanyaan terbuka

1. Berapa jumlah anggota dan kegiatan per bulan? (menentukan kuota hosting dan jumlah kartu)
2. Apakah anggota wajib punya HP NFC, atau cukup petugas yang scan? (asumsi PRD: petugas yang scan)
3. Siapa yang menjadi pemilik data setelah pergantian kepengurusan? (per organisasi)
4. Apakah jurusan/kampus punya kebijakan data yang perlu diikuti?
5. Apakah ada kebutuhan integrasi dengan sistem kampus (misalnya SSO)?
6. Berapa lama data periode lama disimpan?

## 18. Kriteria selesai MVP

- F1-F12 lolos acceptance criteria pada US-1 sampai US-11.
- Satu kegiatan nyata (≥ 30 peserta) berjalan dengan sistem, termasuk satu skenario offline.
- Selisih rekap vs. pengamatan panitia < 2%.
- Backup dan restore database terverifikasi.
- Dokumentasi pengguna singkat untuk Super Admin dan Petugas tersedia.
