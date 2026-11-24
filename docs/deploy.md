# Panduan Deploy (VPS + Docker)

Satu VPS menjalankan seluruh stack: aplikasi (PHP-FPM), web server Caddy dengan **HTTPS otomatis**,
PostgreSQL, Valkey (cache/queue/session), queue worker (ekspor), scheduler (tutup kegiatan otomatis),
dan backup harian. Stack ini diuji oleh workflow **Deploy (uji stack Docker)** di GitHub Actions
(termasuk backup & restore) — otomatis di setiap merge ke `main`, tiap Minggu, atau manual lewat
*Actions → Deploy → Run workflow*.

## Kebutuhan

- VPS Linux 1 vCPU / 2 GB RAM (cukup untuk satu organisasi), disk ≥ 20 GB.
- Docker Engine + plugin Compose.
- Domain/subdomain, mis. `absen.organisasi.id`, dengan **DNS A record** ke IP VPS.
- Port 80 dan 443 terbuka (Caddy butuh port 80 untuk menerbitkan sertifikat).

## Pasang pertama kali

```bash
git clone https://github.com/ranupaners-arch/kartu-anggota-nfc.git
cd kartu-anggota-nfc/deploy
cp .env.example .env
```

Isi `deploy/.env` (semua yang bertanda **WAJIB**):

| Variabel | Isi |
|---|---|
| `SITE_ADDRESS` | domain saja, mis. `absen.organisasi.id` |
| `APP_URL` | `https://` + domain |
| `INSTANCE_NAME` | nama yang tampil di app saat petugas menghubungkan |
| `APP_KEY` | `base64:` + hasil `openssl rand -base64 32` |
| `CARD_KEY_k1` | hasil `openssl rand -hex 32` — **jangan pernah diganti** setelah kartu pertama ditulis |
| `DB_PASSWORD` | password acak, mis. `openssl rand -hex 16` |

Jalankan dan buat organisasi + Super Admin pertama:

```bash
docker compose up -d --build
docker compose exec app php artisan app:setup
```

`app:setup` menanyakan nama organisasi, periode berjalan, nama & email Super Admin, lalu menampilkan
**password sementara sekali**. Login di `https://domain/masuk`, lalu ganti password di **Akun Saya**.

Hubungkan app petugas: dashboard → **Pengaturan → Aplikasi** → scan QR dari app.

## Deploy dari image GHCR (tanpa build di server)

Workflow **Image (GHCR)** membangun `deploy/Dockerfile` (target `app` dan `web`) di setiap push ke
`main` yang menyentuh `backend/` atau `deploy/`, lalu mendorongnya ke
`ghcr.io/ranupaners-arch/kartu-anggota-app` dan `ghcr.io/ranupaners-arch/kartu-anggota-web`
dengan tag SHA commit. Tag `latest` baru dipindah setelah uji stack lulus (dan deploy berhasil, bila
VPS diatur). Server tidak perlu source repo dan **tidak pernah build**.

Folder server (mis. `/srv/kartu-anggota`) cukup berisi `compose.yaml`, `backup.sh`, `offsite.sh`, dan `.env`
(salin dari `deploy/`, isi `.env` seperti di atas, `chmod 600 .env`):

```bash
docker login ghcr.io              # repo private: token dengan izin read:packages
docker compose pull
docker compose up -d --no-build --wait
docker compose exec app php artisan app:setup   # sekali saja, pada pemasangan pertama
```

Pin ke versi tertentu dengan `APP_IMAGE=ghcr.io/ranupaners-arch/kartu-anggota-app:<sha>` dan
`WEB_IMAGE=...-web:<sha>` di `.env`. Pembaruan versi: tunggu workflow selesai, lalu
`docker compose pull && docker compose up -d --no-build --wait`.

Deploy otomatis (opsional): isi secret repo `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`,
`VPS_KNOWN_HOSTS` (hasil `ssh-keyscan` yang sudah diverifikasi), dan opsional `VPS_PORT`. Job
`deploy` mengirim `compose.yaml`, `backup.sh`, `offsite.sh`, dan `rilis.sh` ke `/srv/kartu-anggota`, lalu menjalankan
`rilis.sh <sha>`: backup, sematkan SHA di `.env`, pull, jalankan, tunggu sehat. Bila backup gagal, rilis
berhenti sebelum ada yang diubah. Tanpa secret itu, langkah deploy dilewati dan dilakukan manual.

Syarat pengguna SSH: pemilik folder `/srv/kartu-anggota` dan `.env`, serta boleh `sudo docker compose`
tanpa password. `compose.yaml` di server **ditimpa** tiap rilis; simpan penyesuaian lokal di
`compose.override.yaml`.

### Kembali ke versi sebelumnya

SHA sebelumnya ada di `.env.sebelumnya` (baris `APP_IMAGE`). Di server:

```bash
cd /srv/kartu-anggota && sh rilis.sh <sha-lama>
```

Ini **tidak membatalkan migrasi database**. Bila versi lama tidak cocok dengan skema baru, pulihkan juga
backup yang dibuat sebelum rilis (lihat *Backup & restore*); data setelah backup itu akan hilang.

## Di belakang reverse proxy (Nginx/Cloudflare)

Bila port 80/443 sudah dipegang Nginx di host (beberapa situs di satu VPS), Caddy jangan memakai
port publik. Di `.env`:

```env
SITE_ADDRESS=:80                      # Caddy hanya HTTP; TLS di Nginx/Cloudflare
APP_URL=https://absen.organisasi.id
HTTP_PORT=127.0.0.1:8085              # hanya loopback
HTTPS_PORT=127.0.0.1:8445
CADDY_TRUSTED_PROXIES=private_ranges  # teruskan X-Forwarded-Proto/For dari Nginx ke PHP
# TRUSTED_PROXIES=...                 # proxy yang dipercaya Laravel; bawaan jaringan privat
```

Contoh vhost Nginx (TLS: sertifikat origin Cloudflare atau certbot):

```nginx
server {
    listen 443 ssl;
    server_name absen.organisasi.id;
    ssl_certificate     /etc/ssl/...;
    ssl_certificate_key /etc/ssl/...;
    client_max_body_size 12m;

    location / {
        proxy_pass http://127.0.0.1:8085;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
    }
}
```

Laravel mempercayai `X-Forwarded-*` hanya dari alamat di `TRUSTED_PROXIES` (bawaan
`127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`), sehingga skema `https`, cookie aman, dan
QR hubungkan app tetap benar. Bila memakai Cloudflare, aktifkan `real_ip` di Nginx agar IP klien asli
tercatat.

## Simpan di tempat aman (di luar VPS)

- Isi `deploy/.env` — terutama `APP_KEY` dan `CARD_KEY_k1`. Tanpa `CARD_KEY_k1` yang sama,
  **semua kartu yang sudah ditulis tidak bisa dipakai** lagi.
- Salinan backup (lihat di bawah).

## Pembaruan versi

Dari image GHCR: lihat bagian di atas. Bila SHA sudah disematkan di `.env`, `docker compose pull` tidak
berpindah versi; pakai `sh rilis.sh <sha>` (salin dari `deploy/`).
Build dari source:

```bash
cd kartu-anggota-nfc && git pull
cd deploy && docker compose up -d --build
```

Migrasi database berjalan otomatis saat container `app` dimulai.

### Perbaikan waktu scan lama (sekali)

Versi sebelum perbaikan zona waktu menyimpan jam scan dari app 7 jam lebih awal (status Hadir/Telat tetap benar).
Setelah memperbarui, backup dulu, lihat simulasinya, lalu terapkan:

```bash
docker compose exec -T backup /bin/sh /backup.sh now
docker compose exec app php artisan scan:perbaiki-waktu              # simulasi
docker compose exec app php artisan scan:perbaiki-waktu --terapkan
```

Hanya baris yang pasti bergeser yang diubah; kegiatan yang dibuat lewat API (bukan dashboard) perlu diperiksa manual.

## Backup & restore

Backup otomatis tiap hari pukul `BACKUP_AT` (bawaan 02:00 WIB) ke `deploy/backups/`:
`db-*.dump` (database) dan `files-*.tar.gz` (foto anggota, bukti izin, hasil ekspor).
Backup lebih lama dari `BACKUP_KEEP_DAYS` (bawaan 14 hari) dihapus otomatis.

```bash
docker compose exec backup /bin/sh /backup.sh now          # backup sekarang
ls deploy/backups/                                         # daftar backup
```

Backup yang hanya ada di VPS ikut hilang bila VPS rusak. Aktifkan salinan luar:

### Salinan terenkripsi ke S3

Layanan `offsite` mengunggah backup baru tiap jam ke penyimpanan S3-compatible. Isi dan nama berkas
dienkripsi di server sebelum dikirim, lalu dicocokkan. Isi di `.env`:

```env
COMPOSE_PROFILES=offsite
OFFSITE_ENDPOINT=https://s3.contoh.id
OFFSITE_REGION=auto
OFFSITE_BUCKET=nama-bucket              # buat dulu bucketnya, privat
OFFSITE_ACCESS_KEY=...
OFFSITE_SECRET_KEY=...
OFFSITE_PASSWORD=...                    # openssl rand -hex 32
OFFSITE_PING_URL=https://...            # opsional: pemantau bergaya healthchecks.io
```

```bash
docker compose up -d offsite
docker compose exec offsite /bin/sh /offsite.sh now    # unggah & periksa sekarang
docker compose exec offsite /bin/sh /offsite.sh list   # daftar salinan luar
```

- **Simpan `OFFSITE_PASSWORD` di luar VPS.** Tanpa itu salinan tidak bisa dibuka oleh siapa pun.
- Salinan luar disimpan `OFFSITE_KEEP_DAYS` hari (bawaan 90); yang lama dihapus hanya setelah yang baru cocok.
- Alarm: bila `OFFSITE_PING_URL` diisi, pemantau diberi tahu saat unggahan gagal **atau** backup harian tidak
  ada yang lebih baru dari 26 jam. Atur jadwal pemantau 1 jam dengan toleransi 1 jam.
- Ambil kembali: `docker compose exec offsite /bin/sh /offsite.sh pull db-....dump`, lalu restore seperti di bawah.
- Agar penyerang di VPS tidak bisa menghapus salinan, aktifkan versi/penguncian objek di penyedia S3.

Restore (menimpa database! hentikan akses dulu):

```bash
docker compose stop app worker scheduler
docker compose exec backup /bin/sh /backup.sh restore /backups/db-YYYYMMDD-HHMMSS.dump /backups/files-YYYYMMDD-HHMMSS.tar.gz
docker compose start app worker scheduler
```

PRD mewajibkan **uji restore tiap kuartal**: pulihkan ke database lain tanpa menimpa produksi,
lalu periksa isinya:

```bash
docker compose exec db createdb -U kartu_anggota restore_test
docker compose exec backup /bin/sh /backup.sh restore /backups/db-....dump "" restore_test
docker compose exec db psql -U kartu_anggota -d restore_test -c "select count(*) from members"
docker compose exec db dropdb -U kartu_anggota restore_test
```

## Email (lupa password & ringkasan mingguan)

Tanpa pengaturan email (`MAIL_MAILER=log`, bawaan), **lupa password** dan **ringkasan mingguan**
tidak aktif: reset password hanya lewat pengurus, dan tautan "Lupa password?" tidak muncul. Untuk
mengaktifkan, isi di `.env` lalu `docker compose up -d`:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.contoh.id        # mis. smtp.gmail.com (pakai App Password), atau SMTP layanan email
MAIL_PORT=587
MAIL_USERNAME=akun@contoh.id
MAIL_PASSWORD=rahasia
MAIL_SCHEME=null                # null = STARTTLS di port 587; "smtps" untuk port 465
MAIL_FROM_ADDRESS=noreply@contoh.id
MAIL_FROM_NAME="Kartu Anggota"
```

Periksa di dashboard: **Pengaturan → Sistem → Email** harus *Aktif*, lalu **Kirim email uji**.
Ringkasan dikirim Senin 07.00 WIB oleh scheduler (`php artisan ringkasan:mingguan` untuk mengirim
manual); pengurus bisa mematikannya di **Akun → Ringkasan mingguan**.

## Notifikasi push portal anggota

Pengingat ±1 jam sebelum kegiatan dan pengumuman baru ke HP anggota yang memakai **portal web** (Web
Push standar, tanpa Firebase). Nonaktif sampai kunci VAPID diisi:

```bash
docker compose exec app php artisan push:vapid   # cetak VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY / VAPID_SUBJECT
```

Salin ketiga baris itu ke `.env` (ganti `VAPID_SUBJECT` dengan `mailto:` email pengurus), lalu
`docker compose up -d`. Periksa di **Pengaturan → Sistem → Notifikasi push portal** (*Aktif*). Kunci
privat rahasia — jangan dibagikan; bila diganti, anggota perlu mengaktifkan notifikasi lagi.
Pengingat dikirim scheduler (`push:pengingat`, tiap 5 menit); butuh HTTPS.

## Pemantauan & masalah umum

| Perlu | Perintah |
|---|---|
| Status layanan | `docker compose ps` |
| Log aplikasi / worker | `docker compose logs -f app worker` |
| Cek kesehatan | `curl https://domain/up` |
| Sertifikat HTTPS gagal | pastikan DNS sudah mengarah & port 80/443 terbuka; lihat `docker compose logs web` |
| Ekspor tertahan "pending" | `docker compose ps worker` harus `running` |

## Build APK untuk server ini

APK rilis hanya mau terhubung ke `https://`. Untuk satu server pusat, build dengan
`--dart-define=SERVER_MODE=fixed --dart-define=API_URL=https://domain`; untuk server per organisasi
(bawaan), petugas menghubungkan lewat QR di Pengaturan → Aplikasi.

## 2FA darurat

Bila Super Admin kehilangan HP **dan** kode cadangan, matikan 2FA akunnya dari server:

```bash
docker compose exec app php artisan app:2fa-reset admin@organisasi.id
```

Pengguna lain cukup direset Super Admin di dashboard: Pengguna & Peran → Reset 2FA.

## Privasi & retensi data

- **Persetujuan:** anggota baru (form maupun impor CSV) hanya bisa disimpan bila admin mencentang
  bahwa anggota sudah menyetujui datanya diproses. Waktu & pencatatnya terlihat di detail anggota.
- **Hapus data pribadi:** detail anggota → Privasi → *Hapus data pribadi* (hanya untuk anggota yang
  tidak aktif di periode berjalan). Nama, nomor, kontak, foto, alasan & lampiran izin, dan akun login
  dihapus permanen; catatan kehadiran tetap dihitung di rekap sebagai "Anggota terhapus".
- **Retensi otomatis** (Pengaturan → Privasi), dijalankan scheduler tiap hari pukul 02.30:
  alasan & lampiran izin dihapus N hari setelah periode berakhir (bawaan 180), data anggota dihapus
  N bulan setelah periode terakhirnya (bawaan mati), berkas ekspor dihapus setelah
  `EXPORT_RETENTION_DAYS` hari (bawaan 7).

```bash
docker compose exec app php artisan app:retention --dry-run   # lihat apa yang akan dihapus
```

## Pemantauan (observabilitas)

- **Status sistem:** dashboard → Pengaturan → Sistem, atau `docker compose exec app php artisan app:health`.
  Diperiksa: database, cache (Redis/Valkey), penyimpanan berkas, serta *detak* queue worker & scheduler
  (dikirim tiap menit; lebih dari 5 menit tanpa detak = gagal). Container `app` memakai pemeriksaan
  ini sebagai healthcheck Docker (`docker compose ps` menampilkan `healthy`/`unhealthy`).
- **Pemantau uptime** (mis. UptimeRobot, Uptime Kuma): pantau `https://<domain>/api/health`;
  HTTP 503 bila ada yang gagal. Isi `HEALTH_TOKEN` lalu kirim header `X-Health-Token` untuk melihat detail.
- **Log:** `docker compose logs -f app worker scheduler`. Format JSON satu baris; tiap baris membawa
  `request_id` (juga di header respons `X-Request-Id`, dibuat Caddy), `user_id`, dan `organization_id`
  — tanpa nama/email. Cari semua log satu request: `docker compose logs app | grep <request_id>`.
- **Pelacakan error (opsional):** isi `SENTRY_LARAVEL_DSN` (sentry.io atau GlitchTip self-hosted), lalu
  `docker compose up -d`. Data pribadi tidak dikirim; error dikelompokkan dengan request_id-nya.

## Performa

Target PRD: scan < 1 detik (p95), batch 200 scan < 5 detik. Dibuktikan otomatis di CI uji stack
(`.github/scripts/uji-beban-scan.py`: 4 petugas scan bersamaan + satu batch 200 scan offline) dan
dijaga oleh anggaran query di `tests/Feature/ScanPerformanceTest.php` (≤ 12 query per scan,
± 2 query per scan dalam batch). Batas laju scan: 120 per menit per petugas.
