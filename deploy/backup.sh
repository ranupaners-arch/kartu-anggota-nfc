#!/bin/sh
# Backup database (pg_dump) + berkas (foto, bukti izin, ekspor) ke /backups.
#   backup.sh            → jalan terus, backup tiap hari pukul $BACKUP_AT (bawaan 02:00, zona TZ)
#   backup.sh now        → sekali backup sekarang
#   backup.sh restore <db.dump> [files.tar.gz] [nama_db_tujuan]
#                         → pulihkan ke database tujuan (bawaan $PGDATABASE; HATI-HATI: menimpa)
set -eu
BACKUP_DIR=/backups
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
mkdir -p "$BACKUP_DIR"

backup_now() {
  stamp=$(date +%Y%m%d-%H%M%S)
  pg_dump --format=custom --no-owner --file="$BACKUP_DIR/db-$stamp.dump"
  tar -czf "$BACKUP_DIR/files-$stamp.tar.gz" -C /storage app
  find "$BACKUP_DIR" -type f \( -name 'db-*.dump' -o -name 'files-*.tar.gz' \) -mtime +"$KEEP_DAYS" -delete
  echo "[$(date '+%F %T')] backup selesai: db-$stamp.dump, files-$stamp.tar.gz"
}

case "${1:-loop}" in
  now)
    backup_now ;;
  restore)
    dump="${2:?pakai: backup.sh restore <db.dump> [files.tar.gz] [nama_db]}"
    target="${4:-$PGDATABASE}"
    pg_restore --clean --if-exists --no-owner --dbname="$target" "$dump"
    if [ -n "${3:-}" ]; then tar -xzf "$3" -C /storage; fi
    echo "pulih ke database $target" ;;
  loop)
    at="${BACKUP_AT:-02:00}"
    echo "backup harian pukul $at ($(date +%Z)), simpan $KEEP_DAYS hari"
    while true; do
      now=$(date +%s)
      next=$(date -d "$(date +%F) $at" +%s 2>/dev/null || date -D '%Y-%m-%d %H:%M' -d "$(date +%F) $at" +%s)
      [ "$next" -le "$now" ] && next=$((next + 86400))
      sleep $((next - now))
      backup_now || echo "[$(date '+%F %T')] BACKUP GAGAL" >&2
    done ;;
  *) echo "perintah tidak dikenal: $1" >&2; exit 2 ;;
esac
