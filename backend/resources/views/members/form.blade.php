@extends('layouts.app', ['title' => $member->exists ? 'Ubah Anggota' : 'Tambah Anggota'])
@section('content')
<h1>{{ $member->exists ? 'Ubah Anggota' : 'Tambah Anggota' }}</h1>
<form method="post" action="{{ $member->exists ? route('members.update', $member) : route('members.store') }}" class="card">
    @csrf @if ($member->exists) @method('put') @endif
    <div class="form-row">
        <div><label>Nomor anggota</label><input name="member_no" value="{{ old('member_no', $member->member_no) }}" required></div>
        <div><label>Nama</label><input name="name" value="{{ old('name', $member->name) }}" required></div>
        <div><label>Angkatan</label><input name="cohort" value="{{ old('cohort', $member->cohort) }}"></div>
    </div>
    <div class="form-row">
        <div><label>Email</label><input type="email" name="email" value="{{ old('email', $member->email) }}"></div>
        <div><label>Telepon</label><input name="phone" value="{{ old('phone', $member->phone) }}"></div>
    </div>
    <div class="form-row">
        <div><label>Divisi (periode ini)</label><select name="division_id"><option value="">—</option>@foreach ($divisions as $d)<option value="{{ $d->id }}" @selected(old('division_id', $membership->division_id) == $d->id)>{{ $d->name }}</option>@endforeach</select></div>
        <div><label>Status keanggotaan</label><select name="status">@foreach (\App\Services\MemberService::STATUSES as $s)<option value="{{ $s }}" @selected(old('status', $membership->status) === $s)>{{ $s }}</option>@endforeach</select></div>
        <div><label>Jabatan</label><input name="role" value="{{ old('role', $membership->role) }}"></div>
    </div>
    <p><button>Simpan</button> <a href="{{ $member->exists ? route('members.show', $member) : route('members.index') }}">Batal</a></p>
</form>
@endsection
