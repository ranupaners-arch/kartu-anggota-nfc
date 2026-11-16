@extends('layouts.app', ['title' => $user->exists ? 'Ubah Pengguna' : 'Tambah Pengguna'])
@section('content')
<h1>{{ $user->exists ? 'Ubah Pengguna' : 'Tambah Pengguna' }}</h1>
<form method="post" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="card">
    @csrf @if ($user->exists) @method('put') @endif
    <div class="form-row">
        <div><label>Nama</label><input name="name" value="{{ old('name', $user->name) }}" required></div>
        <div><label>Email</label><input type="email" name="email" value="{{ old('email', $user->email) }}" required></div>
    </div>
    <div class="form-row">
        <div><label>Peran</label><select name="role">@foreach (['super_admin' => 'Super Admin', 'division_admin' => 'Admin Divisi', 'scanner' => 'Petugas Scan', 'member' => 'Anggota'] as $v => $l)<option value="{{ $v }}" @selected(old('role', $user->role) === $v)>{{ $l }}</option>@endforeach</select></div>
        <div><label>Divisi (wajib untuk Admin Divisi)</label><select name="division_id"><option value="">—</option>@foreach ($divisions as $d)<option value="{{ $d->id }}" @selected(old('division_id', $user->division_id) == $d->id)>{{ $d->name }}</option>@endforeach</select></div>
        <div><label>Tautkan ke nomor anggota (opsional)</label><input name="member_no" value="{{ old('member_no', $user->member?->member_no) }}"></div>
    </div>
    @unless ($user->exists)<p class="muted">Password sementara dibuat otomatis dan ditampilkan sekali setelah disimpan.</p>@endunless
    <p><button>Simpan</button> <a href="{{ route('users.index') }}">Batal</a></p>
</form>
@endsection
