<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
  h1 { font-size: 14px; margin: 0 0 2px; }
  .meta { color: #555; margin-bottom: 10px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #bbb; padding: 4px 6px; text-align: left; }
  th { background: #eee; }
</style>
</head>
<body>
  <h1>{{ $title }}</h1>
  <div class="meta">{{ $org->name }} · dibuat {{ now()->translatedFormat('d F Y H:i') }}</div>
  <table>
    <thead><tr>@foreach ($headings as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>
      @forelse ($rows as $row)
        <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
      @empty
        <tr><td colspan="{{ count($headings) }}">Tidak ada data.</td></tr>
      @endforelse
    </tbody>
  </table>
</body>
</html>
