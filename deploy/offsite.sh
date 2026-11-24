#!/bin/sh
# Salin backup ke penyimpanan S3 di luar server, terenkripsi (rclone crypt: isi & nama berkas).
#   offsite.sh          → jalan terus: tiap jam unggah backup baru, periksa, kirim tanda sehat
#   offsite.sh now      → sekali unggah & periksa sekarang
#   offsite.sh list     → daftar backup di penyimpanan luar
#   offsite.sh pull <berkas>  → ambil satu backup ke /backups (tidak menimpa berkas yang ada)
set -eu
: "${OFFSITE_BUCKET:?isi OFFSITE_BUCKET}" "${OFFSITE_ENDPOINT:?isi OFFSITE_ENDPOINT}"
: "${OFFSITE_ACCESS_KEY:?isi OFFSITE_ACCESS_KEY}" "${OFFSITE_SECRET_KEY:?isi OFFSITE_SECRET_KEY}"
: "${OFFSITE_PASSWORD:?isi OFFSITE_PASSWORD}"
BACKUP_DIR=/backups
KEEP_DAYS="${OFFSITE_KEEP_DAYS:-90}"
MAX_AGE_HOURS="${OFFSITE_MAX_AGE_HOURS:-26}"

export RCLONE_CONFIG=/notfound RCLONE_CONFIG_S3_TYPE=s3 RCLONE_CONFIG_S3_PROVIDER=Other
export RCLONE_CONFIG_S3_ENDPOINT="$OFFSITE_ENDPOINT" RCLONE_CONFIG_S3_REGION="${OFFSITE_REGION:-auto}"
export RCLONE_CONFIG_S3_ACCESS_KEY_ID="$OFFSITE_ACCESS_KEY" RCLONE_CONFIG_S3_SECRET_ACCESS_KEY="$OFFSITE_SECRET_KEY"
export RCLONE_CONFIG_S3_NO_CHECK_BUCKET=true
export RCLONE_CONFIG_LUAR_TYPE=crypt RCLONE_CONFIG_LUAR_REMOTE="s3:$OFFSITE_BUCKET/kartu-anggota"
RCLONE_CONFIG_LUAR_PASSWORD=$(printf '%s' "$OFFSITE_PASSWORD" | rclone obscure -)
export RCLONE_CONFIG_LUAR_PASSWORD

# Pemantau bergaya healthchecks.io: tanda sehat ke URL, tanda gagal ke URL/fail. Diam = juga alarm.
ping() {
  [ -n "${OFFSITE_PING_URL:-}" ] || return 0
  wget -q -T 15 -O /dev/null "${OFFSITE_PING_URL%/}${1:-}" || echo "tanda ke pemantau gagal dikirim" >&2
}

# Backup lokal harus ada yang baru & tidak kosong (menangkap backup harian yang berhenti).
fresh() {
  for kind in 'db-*.dump' 'files-*.tar.gz'; do
    if [ -z "$(find "$BACKUP_DIR" -maxdepth 1 -type f -name "$kind" -size +0 -mmin -$((MAX_AGE_HOURS * 60)) | head -n 1)" ]; then
      echo "tidak ada $kind lebih baru dari $MAX_AGE_HOURS jam" >&2
      return 1
    fi
  done
}

filters() { set -- --include 'db-*.dump' --include 'files-*.tar.gz' "$@"; rclone "$@"; }

upload() {
  fresh || return 1
  # --min-age: berkas yang masih ditulis pg_dump/tar dilewati sampai putaran berikutnya.
  # --immutable: backup yang sudah diunggah tidak pernah ditimpa (mis. berkas lokal rusak).
  filters copy "$BACKUP_DIR" luar: --max-depth 1 --min-age "${OFFSITE_MIN_AGE:-10m}" --immutable || return 1
  filters cryptcheck "$BACKUP_DIR" luar: --max-depth 1 --min-age "${OFFSITE_MIN_AGE:-10m}" --one-way || return 1
  # Hapus yang lama hanya setelah yang baru terbukti utuh.
  filters delete luar: --min-age "${KEEP_DAYS}d" || return 1
  echo "[$(date '+%F %T')] salinan luar selesai & cocok"
}

run() {
  if upload; then
    ping
  else
    echo "[$(date '+%F %T')] SALINAN LUAR GAGAL" >&2
    ping /fail
    return 1
  fi
}

case "${1:-loop}" in
  now) run ;;
  list) rclone lsl luar: ;;
  pull)
    f="${2:?pakai: offsite.sh pull <berkas>}"
    case "$f" in */* | .*) echo "nama berkas tidak valid" >&2; exit 2 ;; esac
    [ ! -e "$BACKUP_DIR/$f" ] || { echo "$f sudah ada di $BACKUP_DIR" >&2; exit 1; }
    rclone copyto "luar:$f" "$BACKUP_DIR/$f"
    [ -s "$BACKUP_DIR/$f" ] || { echo "$f tidak ditemukan" >&2; exit 1; }
    echo "diambil: $BACKUP_DIR/$f" ;;
  loop)
    while true; do
      run || true
      sleep "${OFFSITE_EVERY:-3600}"
    done ;;
  *) echo "perintah tidak dikenal: $1" >&2; exit 2 ;;
esac
