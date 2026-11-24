#!/bin/sh
# Pasang satu versi image GHCR di server (dipanggil workflow Image; bisa juga manual).
#   sh rilis.sh <sha>   → backup, sematkan SHA di .env, pull, jalankan, tunggu sehat
# Versi lama disimpan di .env.sebelumnya. Migrasi database TIDAK ikut dibatalkan saat kembali ke SHA lama.
# Berkas compose.yaml.baru & backup.sh.baru (bila ada) dipasang setelah backup berhasil.
set -eu
sha="${1:?pakai: rilis.sh <sha>}"
printf '%s' "$sha" | grep -Eq '^[0-9a-f]{40}$' || { echo "SHA tidak valid: $sha" >&2; exit 2; }
cd "$(dirname "$0")"
test -f .env || { echo ".env tidak ada" >&2; exit 2; }
repo=ghcr.io/ranupaners-arch/kartu-anggota
compose() { sudo docker compose "$@"; }

# Backup sebelum migrasi. Hanya dilewati pada pemasangan pertama (belum ada container database).
if [ -f compose.yaml ]; then
  db=$(compose ps -aq db)
  if [ -n "$db" ]; then
    compose exec -T backup /bin/sh /backup.sh now
  fi
fi

umask 077
# mv (bukan menimpa): backup.sh yang sedang berjalan tetap membaca berkas lamanya.
for f in compose.yaml backup.sh; do
  if [ -f "$f.baru" ]; then
    chmod 644 "$f.baru"
    mv "$f.baru" "$f"
  fi
done
cp .env .env.sebelumnya
awk -v app="APP_IMAGE=$repo-app:$sha" -v web="WEB_IMAGE=$repo-web:$sha" \
  '!/^(APP|WEB)_IMAGE=/ { print } END { print app; print web }' .env > .env.baru
mv .env.baru .env

compose pull app web
compose up -d --no-build --wait --wait-timeout 600
# backup.sh dipasang sebagai satu berkas: container perlu dibuat ulang agar memakai versi baru.
compose up -d --no-build --no-deps --force-recreate backup
echo "rilis $sha selesai"
