@extends('layouts.app', ['title' => 'Rekap & Ekspor'])
@section('content')
<h1>Rekap &amp; Ekspor</h1>
@include('partials.tabs', ['tabs' => array_filter(['periode' => auth()->user()->isSuperAdmin() ? 'Periode' : null, 'divisi' => 'Divisi', 'ekspor' => 'Hasil Ekspor']), 'current' => $tab])
@if ($tab === 'ekspor')
    <table><tr><th>Dibuat</th><th>Laporan</th><th>Format</th><th>Status</th><th></th></tr>
        @forelse ($exports as $x)
            <tr><td>{{ $x->created_at->format('d M Y H:i') }}</td><td>{{ $x->report === 'event' ? 'Kegiatan' : 'Periode' }}</td><td>{{ strtoupper($x->format) }}</td>
                <td><span class="pill {{ $x->status }}">{{ $x->status }}</span><div class="muted">{{ $x->error }}</div></td>
                <td>@if ($x->status === 'ready')<a href="{{ route('exports.download', $x) }}">Unduh</a>@endif</td></tr>
        @empty
            <tr><td colspan="5" class="muted">Belum ada ekspor.</td></tr>
        @endforelse
    </table>
    {{ $exports->links() }}
@else
    <form method="get" class="toolbar"><input type="hidden" name="tab" value="{{ $tab }}">
        <select name="term_id">@foreach ($terms as $t)<option value="{{ $t->id }}" @selected($term?->id === $t->id)>{{ $t->name }}</option>@endforeach</select>
        @if ($tab === 'divisi')<select name="division_id"><option value="">Pilih divisi</option>@foreach ($divisions as $d)<option value="{{ $d->id }}" @selected($divisionId === $d->id)>{{ $d->name }}</option>@endforeach</select>@endif
        <button class="btn-secondary">Tampilkan</button><span class="spacer"></span>
        @if ($report)
            @foreach (['xlsx' => 'Ekspor Excel', 'pdf' => 'Ekspor PDF'] as $f => $l)
                <button form="export-{{ $f }}">{{ $l }}</button>
            @endforeach
        @endif
    </form>
    @if ($report)
        @foreach (['xlsx', 'pdf'] as $f)
            <form id="export-{{ $f }}" method="post" action="{{ route('exports.store') }}">@csrf
                <input type="hidden" name="report" value="term"><input type="hidden" name="term_id" value="{{ $term->id }}"><input type="hidden" name="division_id" value="{{ $divisionId }}"><input type="hidden" name="format" value="{{ $f }}"></form>
        @endforeach
        <p class="muted">{{ $report['events'] }} kegiatan · rata-rata kehadiran {{ $report['average_rate'] ?? '–' }}%</p>
        <table><tr><th>No.</th><th>Nama</th><th>Divisi</th><th>Hadir</th><th>Telat</th><th>Izin</th><th>Sakit</th><th>Alpa</th><th>% Hadir</th></tr>
            @forelse ($report['rows'] as $r)
                <tr><td>{{ $r['member_no'] }}</td><td>{{ $r['name'] }}</td><td>{{ $r['division'] }}</td><td>{{ $r['hadir'] }}</td><td>{{ $r['telat'] }}</td><td>{{ $r['izin'] }}</td><td>{{ $r['sakit'] }}</td><td>{{ $r['alpa'] }}</td><td>{{ $r['attendance_rate'] ?? '–' }}</td></tr>
            @empty
                <tr><td colspan="9" class="muted">Tidak ada data.</td></tr>
            @endforelse
        </table>
    @elseif ($tab === 'divisi')
        <p class="muted">Pilih divisi.</p>
    @endif
@endif
@endsection
