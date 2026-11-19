#!/usr/bin/env python3
"""Uji beban scan terhadap stack Docker yang berjalan (PRD §13).

Target: scan tunggal p95 < 1 detik saat 4 petugas scan bersamaan; batch 200 scan < 5 detik.
Masukan: JSON dari uji-beban-siapkan.php (argumen 1), alamat server (argumen 2).
"""
import json
import sys
import time
import urllib.request
import uuid
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone

data = json.loads(open(sys.argv[1]).read().strip().splitlines()[-1])
base = sys.argv[2].rstrip('/')
event = data['event_id']
payloads = data['payloads']
PER_PETUGAS = 40


def post(path, token, body):
    req = urllib.request.Request(f'{base}{path}', data=json.dumps(body).encode(), method='POST', headers={
        'Authorization': f'Bearer {token}', 'Accept': 'application/json', 'Content-Type': 'application/json'})
    t = time.perf_counter()
    with urllib.request.urlopen(req, timeout=30) as r:
        out = json.loads(r.read())
    return (time.perf_counter() - t) * 1000, out


def now():
    return datetime.now(timezone.utc).isoformat()


def petugas(i):
    """Satu petugas men-scan 40 kartu berurutan."""
    hasil = []
    for p in payloads[i * PER_PETUGAS:(i + 1) * PER_PETUGAS]:
        ms, out = post(f'/api/events/{event}/scan', data['tokens'][i], {'payload': p, 'client_uuid': str(uuid.uuid4()), 'scanned_at': now()})
        hasil.append((ms, out['status']))
    return hasil


def p95(xs):
    xs = sorted(xs)
    return xs[max(0, int(round(0.95 * len(xs))) - 1)]


# Pemanasan (opcache, koneksi DB) — tidak dihitung.
post(f'/api/events/{event}/scan', data['tokens'][0], {'payload': payloads[-1], 'client_uuid': str(uuid.uuid4()), 'scanned_at': now()})

with ThreadPoolExecutor(max_workers=4) as pool:
    tunggal = [x for hasil in pool.map(petugas, range(4)) for x in hasil]
lat = [ms for ms, _ in tunggal]
status = {s for _, s in tunggal}
print(f'Scan tunggal (4 petugas bersamaan, {len(lat)} scan): p50 {sorted(lat)[len(lat) // 2]:.0f} ms, '
      f'p95 {p95(lat):.0f} ms, maks {max(lat):.0f} ms, status {sorted(status)}')

batch = [{'payload': p, 'client_uuid': str(uuid.uuid4()), 'scanned_at': now()} for p in payloads[160:360]]
ms, out = post(f'/api/events/{event}/scan/batch', data['tokens'][0], {'scans': batch})
print(f'Batch 200 scan: {ms:.0f} ms, ringkasan {out["summary"]}')

gagal = []
if status != {'ok'}:
    gagal.append(f'ada scan tunggal yang tidak ok: {sorted(status)}')
if p95(lat) >= 1000:
    gagal.append(f'p95 scan tunggal {p95(lat):.0f} ms >= 1000 ms')
if out['summary'] != {'ok': 200}:
    gagal.append(f'batch tidak semua ok: {out["summary"]}')
if ms >= 5000:
    gagal.append(f'batch 200 {ms:.0f} ms >= 5000 ms')
if gagal:
    sys.exit('GAGAL: ' + '; '.join(gagal))
print('Target performa PRD terpenuhi.')
