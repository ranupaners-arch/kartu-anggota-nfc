#!/usr/bin/env bash
# Development: tampilkan QR anggota di terminal untuk dipindai app petugas.
# Pemakaian: scripts/qr-anggota.sh [URL] [email] [password]
# QR dirotasi tiap 60 detik (berlaku 90 detik), sesuai config/attendance.php.
set -euo pipefail
URL=${1:-http://100.84.251.14:8000}
EMAIL=${2:-anggota@hmps.test}
PASS=${3:-password}
command -v qrencode >/dev/null || { echo "Perlu qrencode: sudo pacman -S qrencode"; exit 1; }

TOKEN=$(curl -fsS -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"device_uuid\":\"terminal-qr\",\"device_name\":\"Terminal QR\"}" \
  "$URL/api/auth/login" | python3 -c 'import sys,json;print(json.load(sys.stdin)["token"])')

while true; do
  PAYLOAD=$(curl -fsS -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" "$URL/api/me/qr" \
    | python3 -c 'import sys,json;print(json.load(sys.stdin)["payload"])')
  clear
  echo "QR anggota: $EMAIL  (perbarui otomatis tiap 60 detik, Ctrl+C untuk berhenti)"
  qrencode -t ansiutf8 -m 2 "$PAYLOAD"
  sleep 60
done
