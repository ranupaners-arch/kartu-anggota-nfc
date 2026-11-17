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
