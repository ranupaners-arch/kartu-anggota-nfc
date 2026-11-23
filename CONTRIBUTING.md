# Panduan Kontribusi

Terima kasih sudah mau ikut membangun **Kartu Anggota NFC**! Kontribusi apa pun dihargai: laporan bug, ide fitur, perbaikan dokumentasi, terjemahan, maupun kode.

## Sebelum mulai

- Cek [Issues](https://github.com/ranupaners-arch/kartu-anggota-nfc/issues) dulu supaya tidak dobel. Untuk fitur besar, buka issue dan diskusikan sebelum menulis kode.
- **Celah keamanan jangan dibuka di issue publik.** Ikuti [SECURITY.md](SECURITY.md).
- Baca [docs/PRD.md](docs/PRD.md) untuk memahami tujuan produk.

## Menyiapkan lingkungan

Ikuti bagian [Menjalankan untuk pengembangan](README.md#menjalankan-untuk-pengembangan) di README (PHP 8.3+, Composer, Node.js, PostgreSQL, Valkey/Redis; Flutter untuk app).

Jangan pernah commit `.env`, keystore, kunci kartu (`CARD_KEY_*`), atau data anggota asli. Pakai data contoh dari seeder.

## Alur kerja

1. Fork repo, lalu buat branch dari `main`: `feat/…`, `fix/…`, `docs/…`, `refactor/…`, `test/…`.
2. Buat perubahan kecil dan fokus: satu PR untuk satu tujuan.
3. Jalankan tes sebelum push:
   ```bash
   cd backend && php artisan test     # backend + dashboard
   cd app && flutter test             # app Android
   ```
4. Buka pull request ke `main` dan isi deskripsinya: apa yang berubah, kenapa, cara mengujinya, dan screenshot untuk perubahan tampilan.
5. Maintainer meninjau PR. CI harus hijau sebelum merge. Untuk kontributor luar, workflow baru berjalan setelah disetujui maintainer.

## Format commit

Pakai [Conventional Commits](https://www.conventionalcommits.org/) berbahasa Indonesia:

```
tipe(scope): ringkasan imperatif singkat

Penjelasan kenapa perubahan ini dibutuhkan (opsional).
```

- `tipe`: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `build`, `ci`, `chore`, `perf`.
- `scope`: `backend`, `dashboard`, `app`, `deploy`, `docs`, dan sejenisnya.
- Huruf kecil, sekitar 50 karakter, tanpa titik di akhir. Satu commit untuk satu perubahan logis.

Contoh: `fix(app): antre ulang scan saat sinkron gagal`

## Gaya kode

- Backend: ikuti gaya Laravel yang sudah ada (jalankan `./vendor/bin/pint` di folder `backend`).
- Dashboard: React + Inertia + shadcn; pakai komponen yang sudah ada sebelum membuat yang baru.
- App: `dart format` dan `flutter analyze` harus bersih.
- Perubahan perilaku wajib disertai tes.

## Lisensi

Dengan mengirim kontribusi, Anda setuju kontribusi itu dirilis di bawah lisensi yang sama, yaitu [GPL-3.0-or-later](LICENSE).
