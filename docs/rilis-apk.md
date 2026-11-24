# Rilis APK

APK rilis dibangun workflow **Rilis APK** saat tag `app-vX.Y.Z` di-push, ditandatangani kunci rilis, hanya menerima
server `https://`, dan menjadi **draf** GitHub Release. Diterbitkan manual setelah lolos uji di HP.

## Sekali saja: kunci rilis

**Kunci ini dibuat dan disimpan pemilik proyek.** Bila hilang, petugas harus uninstall untuk memasang versi
berikutnya dan scan yang belum terkirim ikut hilang. Bila bocor, orang lain bisa membuat app tiruan.

```bash
mkdir -p ~/.config/kartu-anggota && chmod 700 ~/.config/kartu-anggota
keytool -genkeypair -v -keystore ~/.config/kartu-anggota/apk-rilis.jks -alias kartu-anggota \
  -keyalg RSA -keysize 4096 -validity 10000
keytool -list -v -keystore ~/.config/kartu-anggota/apk-rilis.jks -alias kartu-anggota | grep SHA256:
```

1. Cadangkan `apk-rilis.jks` **dan** password-nya di dua tempat di luar laptop (mis. pengelola password + disk terenkripsi).
2. GitHub → Settings → Environments → buat **rilis-apk**: batasi ke tag `app-v*`, dan aktifkan *Required reviewers*
   bila tersedia.
3. Isi secret di environment itu (password diminta lewat prompt, tidak masuk riwayat shell):

```bash
base64 -w0 ~/.config/kartu-anggota/apk-rilis.jks | gh secret set APK_RILIS_KEYSTORE_BASE64 --env rilis-apk
gh secret set APK_RILIS_STORE_PASSWORD --env rilis-apk
gh secret set APK_RILIS_KEY_PASSWORD --env rilis-apk      # keystore PKCS12: sama dengan store password
gh secret set APK_RILIS_KEY_ALIAS --env rilis-apk --body kartu-anggota
gh variable set APK_RILIS_SHA256 --env rilis-apk --body "AA:BB:..."   # SHA256 dari keytool di atas
```

## Setiap rilis

1. [Daftar uji HP](uji-hp.md) lolos dengan APK uji dari commit yang sama.
2. Tag commit di `main` (versi harus naik; `1.2.3` → versionCode `10203`):

```bash
git checkout main && git pull
git tag app-v1.0.0 && git push origin app-v1.0.0
```

3. Workflow menolak bila tag tidak di `main`, kunci belum ada, sertifikat tidak cocok, atau APK mengizinkan HTTP.
4. Unduh APK dari draf release, cocokkan `SHA256SUMS.txt`, uji di HP, lalu **Publish**.

## Pindah dari APK uji ke APK rilis

Kuncinya berbeda, jadi APK uji harus **di-uninstall** dulu. Uninstall menghapus semua data app:

1. Beranda tidak menampilkan scan tertunda **dan** tidak ada *scan milik akun lain*.
2. Catat isi *Scan ditolak* bila ada.
3. Keluar, uninstall, pasang APK rilis, hubungkan ke server, masuk lagi.

Pembaruan antar-APK rilis cukup dipasang di atasnya.
