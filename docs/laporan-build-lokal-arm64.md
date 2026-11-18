# Laporan: build APK lokal di mesin arm64

Tanggal: 2026-11-16 · Mesin: Arch Linux ARM (aarch64) · Flutter 3.50.0 · JDK 17

## Kesimpulan

**APK tidak bisa dibuild di mesin ini.** Build APK memakai GitHub Actions
(`.github/workflows/app.yml`), dan itu sudah terbukti berhasil (APK debug dan release).

Sejak 2026-11-18, push dan pull request hanya menjalankan `flutter analyze` + `flutter test`.
APK dibangun hanya bila diminta: Actions → App (Flutter) → Run workflow, atau
`gh workflow run app.yml --ref <branch>`; hasilnya di artifact run tersebut.

## Yang dicoba

1. Android SDK dipasang ke folder sementara: cmdline-tools, `platform-tools`,
   `platforms;android-36`, `build-tools;36.0.0`.
2. `flutter doctor` → Android toolchain ✓ (SDK terdeteksi).
3. `flutter build apk --debug` dijalankan.

## Temuan

| Komponen | Arsitektur | Bisa jalan di aarch64? |
|---|---|---|
| `build-tools/36.0.0/aapt2` | x86-64 | Tidak: `cannot execute binary file` |
| `build-tools/36.0.0/zipalign` | x86-64 | Tidak |
| `platform-tools/adb` (dari Google) | x86-64 | Tidak (pakai `adb` dari paket `android-tools` Arch, itu aarch64) |

- `aapt2` wajib dipakai Gradle untuk memproses resource Android. Gradle juga
  mengunduh `aapt2` dari Maven, yang untuk Linux hanya tersedia x86-64.
- Tidak ada emulasi x86-64 (binfmt/qemu-user) di mesin ini.
- Build Gradle berjalan ±50 menit dan mengunduh ±2,3 GB dependensi sebelum
  dihentikan. Tidak sampai selesai, tapi tidak akan bisa melewati langkah `aapt2`.

## Yang tetap bisa dilakukan di mesin ini

- `flutter analyze`, `flutter test`, menulis kode Flutter (tidak butuh Android SDK).
- `adb install` / `adb devices` memakai `adb` sistem (`/usr/bin/adb`, aarch64).

## Opsi bila nanti butuh build lokal

1. Pasang emulasi x86-64 (`qemu-user-static` + `binfmt`): lambat dan tidak resmi.
2. Build di mesin x86-64 lain atau container x86-64 dengan emulasi.
3. Tetap pakai GitHub Actions (disarankan).

## Pembersihan

Dihapus setelah uji (±4,9 GB): SDK dan cache Gradle sementara, `app/android/.gradle`,
`app/android/.kotlin`, `app/build`, `~/.android` (hanya berisi `analytics.settings`
dari build). Pengaturan `flutter config --android-sdk` dikembalikan ke kosong.
