@extends('layouts.app', ['title' => 'Kartu'])
@section('content')
<h1>Kartu</h1>
@include('partials.tabs', ['tabs' => ['semua' => 'Semua Kartu', 'ditolak' => 'Percobaan Ditolak'], 'current' => $tab])
@if ($tab === 'ditolak')
    <p class="muted">Kartu diblokir/hilang yang dicoba dipakai absen.</p>
    <table><tr><th>Waktu</th><th>Kartu</th><th>Status kartu</th><th>Kegiatan</th><th>Perangkat</th></tr>
        @forelse ($items as $log)
            @php $c = $cards[$log->entity_id] ?? null; @endphp
            <tr><td>{{ $log->created_at?->format('d M Y H:i') }}</td><td>{{ $c?->member?->member_no }} {{ $c?->member?->name }}</td>
                <td><span class="pill {{ $log->payload['status'] ?? '' }}">{{ $log->payload['status'] ?? '?' }}</span></td>
                <td>#{{ $log->payload['event_id'] ?? '?' }}</td><td>#{{ $log->payload['device_id'] ?? '?' }}</td></tr>
        @empty
            <tr><td colspan="5" class="muted">Belum ada percobaan.</td></tr>
        @endforelse
    </table>
@else
    <form method="get" class="toolbar"><input type="hidden" name="tab" value="semua">
        <input name="q" value="{{ request('q') }}" placeholder="Cari anggota">
        <select name="status"><option value="">Semua status</option>@foreach (['active' => 'aktif', 'blocked' => 'diblokir', 'lost' => 'hilang', 'unassigned' => 'belum dipakai'] as $v => $l)<option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>@endforeach</select>
        <button class="btn-secondary">Terapkan</button></form>
    @include('cards._table', ['cards' => $items])
@endif
{{ $items->links() }}
@endsection
