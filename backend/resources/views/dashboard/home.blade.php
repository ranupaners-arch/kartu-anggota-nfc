@extends('layouts.app', ['title' => 'Beranda'])
@section('content')
<h1>Beranda</h1>
<p class="muted">Periode: {{ $term?->name ?? 'belum ada periode aktif' }}</p>
<div class="stats">
    <div class="card stat"><div class="n">{{ $activeMembers }}</div><div class="l">Anggota aktif</div></div>
    <div class="card stat"><div class="n">{{ $ongoing->count() }}</div><div class="l">Kegiatan berlangsung</div></div>
    <div class="card stat"><div class="n">{{ $scans7d }}</div><div class="l">Absensi 7 hari terakhir</div></div>
    <div class="card stat"><div class="n"><a href="{{ route('excuses.index') }}">{{ $pendingExcuses }}</a></div><div class="l">Izin menunggu</div></div>
    <div class="card stat"><div class="n"><a href="{{ route('reviews.index') }}">{{ $needsReview }}</a></div><div class="l">Perlu ditinjau</div></div>
</div>
<h2>Sedang berlangsung</h2>
<table>
    <tr><th>Kegiatan</th><th>Check-in ditutup</th><th>Hadir</th></tr>
    @forelse ($ongoing as $e)
        <tr><td><a href="{{ route('events.show', $e) }}">{{ $e->title }}</a></td><td>{{ $e->checkin_closes_at->format('d M H:i') }}</td><td>{{ $e->present_count }}</td></tr>
    @empty
        <tr><td colspan="3" class="muted">Tidak ada.</td></tr>
    @endforelse
</table>
<h2>Akan datang</h2>
<table>
    <tr><th>Kegiatan</th><th>Mulai</th><th>Lokasi</th></tr>
    @forelse ($upcoming as $e)
        <tr><td><a href="{{ route('events.show', $e) }}">{{ $e->title }}</a></td><td>{{ $e->starts_at->format('d M Y H:i') }}</td><td>{{ $e->location }}</td></tr>
    @empty
        <tr><td colspan="3" class="muted">Tidak ada.</td></tr>
    @endforelse
</table>
@endsection
