@extends('layouts.app', ['title' => 'Perangkat Petugas'])
@section('content')
<h1>Perangkat Petugas</h1>
<p class="muted">Perangkat terdaftar otomatis saat login di app. Mencabut akses langsung membatalkan sesinya.</p>
<table><tr><th>Perangkat</th><th>Pengguna</th><th>Terakhir aktif</th><th>Status</th><th></th></tr>
    @forelse ($devices as $d)
        <tr><td>{{ $d->name }}</td><td>{{ $d->user?->name }} <span class="muted">{{ $d->user?->email }}</span></td>
            <td>{{ $d->last_seen_at?->diffForHumans() ?? '—' }}</td>
            <td>@if ($d->revoked_at)<span class="pill blocked">dicabut {{ $d->revoked_at->format('d M') }}</span>@else<span class="pill active">aktif</span>@endif</td>
            <td>@unless ($d->revoked_at)<form method="post" action="{{ route('devices.revoke', $d) }}" onsubmit="return confirm('Cabut akses perangkat ini?')">@csrf<button class="btn-danger">Cabut akses</button></form>@endunless</td></tr>
    @empty
        <tr><td colspan="5" class="muted">Belum ada perangkat.</td></tr>
    @endforelse
</table>
{{ $devices->links() }}
@endsection
