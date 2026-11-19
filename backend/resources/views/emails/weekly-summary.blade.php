@php
    $tz = 'Asia/Jakarta';
    $date = fn ($t) => \Illuminate\Support\Carbon::parse($t)->timezone($tz)->locale('id')->translatedFormat('D, j M');
    $time = fn ($t) => \Illuminate\Support\Carbon::parse($t)->timezone($tz)->format('H.i');
    $pct = fn ($p, $t) => $t ? round($p / $t * 100).'%' : '–';
@endphp
<x-mail::message>
# Ringkasan mingguan

Halo {{ $d['user'] }}, berikut kehadiran {{ $d['organization'] }} periode {{ $date($d['from']) }} – {{ $date($d['to']) }}.

## Perlu tindakan

- **{{ $d['pendingExcuses'] }}** pengajuan izin menunggu keputusan
- **{{ $d['needsReview'] }}** scan perlu ditinjau
- **{{ $d['atRiskCount'] }}** anggota perlu perhatian (Alpa berturut-turut / kehadiran rendah)

@if ($d['closed'])
## Kegiatan 7 hari terakhir

<x-mail::table>
| Kegiatan | Tanggal | Hadir |
|:--|:--|--:|
@foreach ($d['closed'] as $e)
| {{ $e['title'] }} | {{ $date($e['date']) }} | {{ $e['present'] }}/{{ $e['total'] }} ({{ $pct($e['present'], $e['total']) }}) |
@endforeach
</x-mail::table>
@endif

@if ($d['atRisk'])
## Perlu perhatian

@foreach ($d['atRisk'] as $m)
- {{ $m['name'] }} ({{ $m['member_no'] }}{{ $m['division'] ? ', '.$m['division'] : '' }}) — Alpa {{ $m['alpa_streak'] }}× berturut-turut, kehadiran {{ $m['rate'] === null ? '–' : $m['rate'].'%' }}
@endforeach
@if ($d['atRiskCount'] > count($d['atRisk']))
- dan {{ $d['atRiskCount'] - count($d['atRisk']) }} lainnya
@endif
@endif

@if ($d['upcoming'])
## 7 hari ke depan

@foreach ($d['upcoming'] as $e)
- **{{ $e['title'] }}** — {{ $date($e['starts_at']) }} {{ $time($e['starts_at']) }}{{ $e['location'] ? ' · '.$e['location'] : '' }}
@endforeach
@endif

<x-mail::button :url="$d['dashboardUrl']">
Buka dashboard
</x-mail::button>

Email ini dikirim tiap Senin pagi. Matikan di dashboard: **Akun → Ringkasan mingguan**.
</x-mail::message>
