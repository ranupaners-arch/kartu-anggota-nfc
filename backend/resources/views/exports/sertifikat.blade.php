<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $batch->title }} — {{ $event->title }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #18181b; }
        .frame { position: absolute; top: 24pt; left: 24pt; right: 24pt; bottom: 24pt; border: 2pt solid #4f46e5; }
        .inner { position: absolute; top: 30pt; left: 30pt; right: 30pt; bottom: 30pt; border: .5pt solid #a5b4fc; }
        .content { position: absolute; top: 58pt; left: 80pt; right: 80pt; text-align: center; }
        .org { font-size: 13pt; letter-spacing: 1pt; }
        .title { font-size: 34pt; font-weight: bold; color: #4338ca; margin-top: 8pt; }
        .number { font-size: 10pt; color: #52525b; margin-top: 2pt; }
        .label { font-size: 11pt; color: #52525b; margin-top: 18pt; }
        .name { font-weight: bold; margin-top: 4pt; line-height: 1.15; }
        .event { font-size: 15pt; font-weight: bold; margin-top: 4pt; line-height: 1.25; }
        .signers { position: absolute; left: 190pt; top: 430pt; width: 590pt; }
        .signers table { width: 590pt; border-collapse: collapse; }
        .signers td { text-align: center; vertical-align: top; font-size: 10.5pt; padding: 0 10pt; }
        .line { border-top: .75pt solid #18181b; padding-top: 4pt; font-weight: bold; }
        .qr { position: absolute; left: 56pt; top: 420pt; width: 124pt; text-align: center; font-size: 7pt; color: #52525b; }
    </style>
</head>
<body>
<div class="frame"></div>
<div class="inner"></div>
<div class="content">
    <div class="org">{{ $org }}</div>
    <div class="title">{{ $batch->title }}</div>
    <div class="number">Nomor {{ $number }}</div>
    <div class="label">diberikan kepada</div>
    <div class="name" style="font-size: {{ mb_strlen($c->member_name) > 45 ? 18 : (mb_strlen($c->member_name) > 30 ? 22 : 28) }}pt">{{ $c->member_name }}</div>
    <div class="label">sebagai <b>{{ $batch->role }}</b> dalam kegiatan</div>
    <div class="event" @if (mb_strlen($event->title) > 90) style="font-size: 12pt" @endif>{{ $event->title }}</div>
    <div class="label" style="margin-top: 6pt">{{ $date }}@if ($event->location) · {{ \Illuminate\Support\Str::limit($event->location, 80) }}@endif</div>
</div>
<div class="qr">
    <img src="{{ $qr }}" width="80" height="80" alt=""><br>
    Cek keaslian:<br>{{ $c->displayCode() }}
</div>
<div class="signers"><table @if (count($signers) === 1) style="width: 290pt; margin-left: 300pt" @endif>
    <tr>
        @foreach ($signers as [$name, $title])
            <td style="width: 275pt"><div style="height: 42pt"></div><div class="line">{{ $name }}</div>{{ $title }}</td>
        @endforeach
    </tr>
</table></div>
</body>
</html>
