#!/usr/bin/env bash
# Simpan kunci tanda tangan APK uji ke secret GitHub repo ini, agar build CI memakai kunci yang sama.
# Membaca ~/.config/kartu-anggota/apk-uji.properties (dibuat sekali; JANGAN di-commit).
# Pemakaian (dari root repo): bash app/scripts/pasang-kunci-ci.sh
set -euo pipefail
P="${APK_UJI_PROPERTIES:-$HOME/.config/kartu-anggota/apk-uji.properties}"
[ -f "$P" ] || { echo "Tidak ada $P"; exit 1; }
get() { grep "^$1=" "$P" | cut -d= -f2-; }
STORE="$(get storeFile)"
[ -f "$STORE" ] || { echo "Keystore tidak ditemukan: $STORE"; exit 1; }

base64 -w0 "$STORE"        | gh secret set APK_UJI_KEYSTORE_BASE64
get storePassword | tr -d '\n' | gh secret set APK_UJI_STORE_PASSWORD
get keyAlias      | tr -d '\n' | gh secret set APK_UJI_KEY_ALIAS
get keyPassword   | tr -d '\n' | gh secret set APK_UJI_KEY_PASSWORD
echo "Secret terpasang:"; gh secret list | grep APK_UJI
