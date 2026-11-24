# Kartu Anggota NFC

Aplikasi Flutter untuk petugas dan anggota sistem Kartu Anggota NFC.

## Versi APK pengembangan

Versi dasar di `pubspec.yaml` memakai penanda `dev`: `1.0.0-dev+1`.
Workflow **App (Flutter)** menambahkan nomor run GitHub Actions pada nama versi
dan memakainya sebagai nomor build Android (`versionCode`).

Contoh untuk run nomor 42:

- Versi Android (`versionName`): `1.0.0-dev.42`.
- Nomor build Android (`versionCode`): `42`.
- Nama APK: `kartu-anggota-1.0.0-dev.42-arm64.apk`.

Setiap run baru mendapat nomor lebih besar. Mengulang run yang sama tetap
memakai nomor yang sama. Build lokal tanpa override memakai `1.0.0-dev+1`.
APK uji dibuild dalam mode profile khusus arm64.

Penomoran versi tidak mengatasi perbedaan sertifikat signing; pembaruan tanpa
uninstall tetap memerlukan APK yang ditandatangani dengan keystore yang sama.

## Kunci tanda tangan APK uji

Build debug/profile ditandatangani kunci tetap `apk-uji` agar APK dari build
berbeda bisa saling memperbarui. Kunci **tidak** ada di repo:

- Lokal: `~/.config/kartu-anggota/apk-uji.jks` + `apk-uji.properties`
  (atau path lain lewat `APK_UJI_PROPERTIES`). Cadangkan file ini; bila hilang,
  APK berikutnya harus di-uninstall dulu sebelum dipasang.
- CI: secret repo `APK_UJI_*`, diisi dengan `bash app/scripts/pasang-kunci-ci.sh`.

APK rilis memakai kunci terpisah: lihat [docs/rilis-apk.md](../docs/rilis-apk.md). Tanpa kunci itu build
release gagal; untuk menjalankan di HP pakai `flutter run --profile`.
