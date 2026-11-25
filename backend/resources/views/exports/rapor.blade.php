<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Rapor Kehadiran {{ $member->name }}</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
  .head { border-bottom: 2px solid #4F46E5; padding-bottom: 8px; margin-bottom: 12px; }
  h1 { font-size: 16px; margin: 0; }
  .muted { color: #555; }
  .ident td { border: 0; padding: 2px 8px 2px 0; }
  .summary { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 10px -6px 14px; }
  .summary td { border: 1px solid #ddd; border-radius: 4px; text-align: center; padding: 6px; }
  .summary .n { font-size: 15px; font-weight: bold; }
  .rate { background: #EEF2FF; border-color: #C7D2FE !important; }
  table.list { width: 100%; border-collapse: collapse; }
  .list th, .list td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
  .list th { background: #f1f1f1; }
  .foot { margin-top: 14px; color: #666; font-size: 9px; }
</style>
</head>
<body>
  <div class="head">
    <h1>Rapor Kehadiran</h1>
    <div class="muted">{{ $org?->name }} · Periode {{ $term->name }} ({{ \Illuminate\Support\Carbon::parse($term->starts_on)->translatedFormat('d M Y') }} - {{ \Illuminate\Support\Carbon::parse($term->ends_on)->translatedFormat('d M Y') }})</div>
  </div>

  <table class="ident">
    <tr><td class="muted">Nama</td><td><strong>{{ $member->name }}</strong></td></tr>
    <tr><td class="muted">Nomor anggota</td><td>{{ $member->member_no }}</td></tr>
    <tr><td class="muted">Divisi</td><td>{{ $division ?? '-' }}</td></tr>
  </table>

  <table class="summary">
    <tr>
      <td class="rate"><div class="n">{{ $rate === null ? '-' : rtrim(rtrim(number_format($rate, 1, ',', '.'), '0'), ',').'%' }}</div>Kehadiran</td>
      @foreach ($statusLabels as $k => $label)
        <td><div class="n">{{ $counts[$k] }}</div>{{ $label }}</td>
      @endforeach
    </tr>
  </table>

  <table class="list">
    <thead><tr><th style="width:24px">No</th><th>Kegiatan</th><th style="width:90px">Tanggal</th><th style="width:60px">Status</th><th style="width:50px">Scan</th></tr></thead>
    <tbody>
      @forelse ($rows as $i => $r)
        <tr><td>{{ $i + 1 }}</td><td>{{ $r['event'] }}</td><td>{{ $r['date']->translatedFormat('d M Y') }}</td><td>{{ $r['status'] }}</td><td>{{ $r['time'] }}</td></tr>
      @empty
        <tr><td colspan="5" class="muted">Belum ada catatan kehadiran di periode ini.</td></tr>
      @endforelse
    </tbody>
  </table>

  <div class="foot">Kehadiran = (Hadir + Telat) ÷ seluruh catatan. Dibuat {{ now()->timezone(config('app.timezone'))->translatedFormat('d F Y H.i') }} oleh sistem Kartu Anggota.</div>
</body>
</html>
