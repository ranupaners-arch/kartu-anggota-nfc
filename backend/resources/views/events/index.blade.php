@extends('layouts.app', ['title' => 'Kegiatan'])
@section('content')
<div class="toolbar"><h1 style="margin:0">Kegiatan</h1><span class="spacer"></span><a class="btn" href="{{ route('events.create') }}">+ Buat Kegiatan</a></div>
@include('partials.tabs', ['tabs' => ['berlangsung' => 'Berlangsung', 'akan-datang' => 'Akan Datang', 'selesai' => 'Selesai'], 'current' => $tab])
<table>
    <tr><th>Kegiatan</th><th>Mulai</th><th>Check-in</th><th>Lokasi</th><th>Hadir</th><th>Status</th></tr>
    @forelse ($events as $e)
        <tr>
            <td><a href="{{ route('events.show', $e) }}">{{ $e->title }}</a><div class="muted">{{ $e->type }}@if (! $e->is_mandatory) · tidak wajib @endif</div></td>
            <td>{{ $e->starts_at->format('d M Y H:i') }}</td>
            <td>{{ $e->checkin_opens_at->format('H:i') }}–{{ $e->checkin_closes_at->format('H:i') }}<div class="muted">telat setelah {{ $e->late_after->format('H:i') }}</div></td>
            <td>{{ $e->location }}</td>
            <td>{{ $e->present_count }}</td>
            <td>@if ($e->closed_at)<span class="pill">ditutup</span>@else<span class="pill ok">terbuka</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">Tidak ada kegiatan.</td></tr>
    @endforelse
</table>
{{ $events->links() }}
@endsection
