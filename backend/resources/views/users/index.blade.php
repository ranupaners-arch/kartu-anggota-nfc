@extends('layouts.app', ['title' => 'Pengguna & Peran'])
@section('content')
<div class="toolbar"><h1 style="margin:0">Pengguna &amp; Peran</h1><span class="spacer"></span><a class="btn" href="{{ route('users.create') }}">+ Tambah Pengguna</a></div>
<form method="get" class="toolbar"><select name="role"><option value="">Semua peran</option>@foreach (\App\Services\UserService::ROLES as $role)<option value="{{ $role }}" @selected(request('role') === $role)>{{ str_replace('_', ' ', $role) }}</option>@endforeach</select><button class="btn-secondary">Terapkan</button></form>
<table><tr><th>Nama</th><th>Email</th><th>Peran</th><th>Divisi</th><th>Anggota</th><th>Status</th><th>Tindakan</th></tr>
    @foreach ($users as $u)
        <tr>
            <td><a href="{{ route('users.edit', $u) }}">{{ $u->name }}</a></td><td>{{ $u->email }}</td><td>{{ str_replace('_', ' ', $u->role) }}</td>
            <td>{{ $u->division?->name }}</td><td>{{ $u->member?->member_no }}</td>
            <td>@if ($u->disabled_at)<span class="pill blocked">nonaktif</span>@else<span class="pill active">aktif</span>@endif</td>
            <td>@unless ($u->is(auth()->user()))
                <form method="post" action="{{ route('users.reset-password', $u) }}" class="inline" onsubmit="return confirm('Reset password {{ $u->email }}? Ia akan keluar dari semua perangkat.')">@csrf<button class="btn-secondary">Reset password</button></form>
                <form method="post" action="{{ route('users.toggle', $u) }}" class="inline">@csrf<button class="{{ $u->disabled_at ? 'btn-secondary' : 'btn-danger' }}">{{ $u->disabled_at ? 'Aktifkan' : 'Nonaktifkan' }}</button></form>
            @endunless</td>
        </tr>
    @endforeach
</table>
{{ $users->links() }}
@endsection
