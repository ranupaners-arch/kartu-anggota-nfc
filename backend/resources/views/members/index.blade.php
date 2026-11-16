@extends('layouts.app', ['title' => 'Anggota'])
@section('content')
<div class="toolbar"><h1 style="margin:0">Anggota</h1><span class="muted">periode {{ $term->name }}</span><span class="spacer"></span>
    <a class="btn btn-secondary" href="{{ route('members.import') }}">Impor CSV</a><a class="btn" href="{{ route('members.create') }}">+ Tambah Anggota</a></div>
@include('partials.tabs', ['tabs' => ['aktif' => 'Aktif', 'alumni' => 'Alumni', 'semua' => 'Semua'], 'current' => $tab])
<form method="get" class="toolbar">
    <input type="hidden" name="tab" value="{{ $tab }}">
    <input name="q" value="{{ request('q') }}" placeholder="Cari nama / nomor anggota">
    <select name="division_id"><option value="">Semua divisi</option>@foreach ($divisions as $d)<option value="{{ $d->id }}" @selected(request('division_id') == $d->id)>{{ $d->name }}</option>@endforeach</select>
    <button class="btn-secondary">Terapkan</button>
</form>
<table>
    <tr><th>No. Anggota</th><th>Nama</th><th>Divisi</th><th>Angkatan</th><th>Status</th><th>Kartu</th></tr>
    @forelse ($members as $m)
        @php $ms = $m->memberships->first(); @endphp
        <tr>
            <td>{{ $m->member_no }}</td><td><a href="{{ route('members.show', $m) }}">{{ $m->name }}</a></td>
            <td>{{ $ms?->division?->name }}</td><td>{{ $m->cohort }}</td>
            <td>@if ($ms)<span class="pill {{ $ms->status }}">{{ $ms->status }}</span>@else<span class="muted">bukan anggota periode ini</span>@endif</td>
            <td>@if ($m->activeCard)<span class="pill active">aktif</span>@else<span class="muted">belum ada</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">Tidak ada anggota.</td></tr>
    @endforelse
</table>
{{ $members->links() }}
@endsection
