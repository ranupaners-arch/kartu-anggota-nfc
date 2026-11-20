@php
    // Satuan mm. Kartu CR80: 85,6 × 54. Lembar A4: 2 kolom × 5 baris.
    $W = 85.6; $H = 54; $gapX = 8; $gapY = 3;
    $left = (210 - 2 * $W - $gapX) / 2; $top = (297 - 5 * $H - 4 * $gapY) / 2;
    $pos = function (int $i, bool $back) use ($format, $W, $H, $gapX, $gapY, $left, $top) {
        if ($format !== 'a4') return 'left:0;top:0;';
        $col = $i % 2; $row = intdiv($i, 2);
        if ($back) $col = 1 - $col; // dicetak bolak-balik: kolom tertukar
        return 'left:'.($left + $col * ($W + $gapX)).'mm;top:'.($top + $row * ($H + $gapY)).'mm;';
    };
    $valid = \Illuminate\Support\Carbon::parse($term->ends_on)->locale('id')->translatedFormat('M Y');
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Kartu Anggota</title>
<style>
  @page { margin: 0; }
  html { margin: 0; padding: 0; }
  body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #111; }
  .page { position: relative; width: {{ $format === 'a4' ? '210mm' : $W.'mm' }}; height: {{ $format === 'a4' ? '296mm' : '53.9mm' }}; page-break-after: always; overflow: hidden; }
  .page:last-child { page-break-after: auto; }
  .card { position: absolute; width: {{ $W }}mm; height: {{ $H }}mm; overflow: hidden; background: #fff; }
  .cut { outline: 0.2mm dashed #bbb; }
  .band { position: absolute; left: 0; top: 0; width: {{ $W }}mm; height: 13mm; background: #4F46E5; }
  .logo { position: absolute; left: 4mm; top: 2.5mm; width: 8mm; height: 8mm; }
  .org { position: absolute; left: 14mm; top: 2.6mm; width: 68mm; color: #fff; font-size: 7.5pt; font-weight: bold; line-height: 1.15; }
  .kind { position: absolute; left: 14mm; top: 8.2mm; color: #C7D2FE; font-size: 5.5pt; letter-spacing: 0.6pt; }
  .photo { position: absolute; left: 4mm; top: 16mm; width: 21mm; height: 26mm; border-radius: 1.5mm; background: #EEF2FF; overflow: hidden; }
  .photo img { width: 21mm; height: 26mm; }
  .initials { position: absolute; left: 0; top: 8.5mm; width: 21mm; text-align: center; font-size: 15pt; font-weight: bold; color: #6366F1; }
  .name { position: absolute; left: 28mm; top: 16.5mm; width: 54mm; font-size: 10pt; font-weight: bold; line-height: 1.15; }
  .meta { position: absolute; left: 28mm; top: 28mm; width: 54mm; font-size: 6.5pt; line-height: 1.55; }
  .meta .k { color: #666; }
  .foot { position: absolute; left: 0; bottom: 0; width: {{ $W }}mm; height: 7mm; background: #EEF2FF; }
  .foot .t { position: absolute; left: 4mm; top: 2mm; font-size: 5.5pt; color: #4338CA; letter-spacing: 0.4pt; }
  .foot .nfc { position: absolute; right: 4mm; top: 1.6mm; font-size: 5.5pt; color: #4338CA; font-weight: bold; }
  .back .band { height: 9mm; }
  .back .title { position: absolute; left: 4mm; top: 2.6mm; color: #fff; font-size: 7pt; font-weight: bold; }
  .back .body { position: absolute; left: 4mm; top: 12mm; width: 77.6mm; font-size: 6pt; line-height: 1.5; color: #222; }
  .back .body ol { margin: 0 0 0 3.5mm; padding: 0; }
  .back .sign { position: absolute; left: 4mm; bottom: 9mm; width: 77.6mm; font-size: 5.5pt; color: #555; }
</style>
</head>
<body>
@foreach ($pages as $page)
  {{-- Sisi depan --}}
  <div class="page">
    @foreach ($page as $i => $c)
      <div class="card {{ $format === 'a4' ? 'cut' : '' }}" style="{{ $pos($i, false) }}">
        <div class="band"></div>
        <img class="logo" src="{{ $logo }}" alt="">
        <div class="org">{{ $org }}</div>
        <div class="kind">KARTU ANGGOTA</div>
        <div class="photo">
          @if ($c['photo'])<img src="{{ $c['photo'] }}" alt="">@else<div class="initials">{{ $c['initials'] }}</div>@endif
        </div>
        <div class="name">{{ $c['name'] }}</div>
        <div class="meta">
          <span class="k">No. anggota</span> <b>{{ $c['member_no'] }}</b><br>
          @if ($c['division'])<span class="k">Divisi</span> {{ $c['division'] }}<br>@endif
          <span class="k">Berlaku s.d.</span> {{ $valid }}
        </div>
        <div class="foot"><span class="t">PERIODE {{ $term->name }}</span><span class="nfc">NFC ))))</span></div>
      </div>
    @endforeach
  </div>
  {{-- Sisi belakang --}}
  <div class="page">
    @foreach ($page as $i => $c)
      <div class="card back {{ $format === 'a4' ? 'cut' : '' }}" style="{{ $pos($i, true) }}">
        <div class="band"></div>
        <div class="title">Cara memakai kartu</div>
        <div class="body">
          <ol>
            <li>Tempelkan kartu ke bagian belakang HP petugas saat absensi.</li>
            <li>Kartu tertinggal? Tunjukkan <b>QR Saya</b> di app atau portal anggota.</li>
            <li>Kartu hilang? Segera lapor pengurus agar kartu diblokir.</li>
          </ol>
        </div>
        <div class="sign">
          Kartu ini milik {{ $org }} dan tidak dapat dipindahtangankan. Bila ditemukan, mohon kembalikan ke pengurus.
          @if ($c['card_no'])<br>No. kartu: {{ $c['card_no'] }}@endif
        </div>
        <div class="foot"><span class="t">{{ $c['name'] }} · {{ $c['member_no'] }}</span></div>
      </div>
    @endforeach
  </div>
@endforeach
</body>
</html>
