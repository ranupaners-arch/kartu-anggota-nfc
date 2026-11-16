@extends('layouts.app', ['title' => 'Akun Saya'])
@section('content')
<h1>Akun Saya</h1>
<div class="card">
    <div><b>{{ $user->name }}</b> · {{ $user->email }}</div>
    <div class="muted">Peran: {{ str_replace('_', ' ', $user->role) }}@if ($user->member) · Anggota: {{ $user->member->member_no }} {{ $user->member->name }}@endif</div>
</div>
<div class="card">
    <h2 style="margin-top:0">Ganti password</h2>
    <form method="post" action="{{ route('account.password') }}">
        @csrf @method('put')
        <div class="form-row">
            <div><label>Password sekarang</label><input type="password" name="current_password" required></div>
            <div><label>Password baru</label><input type="password" name="password" required></div>
            <div><label>Ulangi password baru</label><input type="password" name="password_confirmation" required></div>
        </div>
        <p class="muted">Minimal 10 karakter, berisi huruf dan angka.</p>
        <button>Simpan</button>
    </form>
</div>
<div class="card"><h2 style="margin-top:0">Keamanan (2FA)</h2><p class="muted">Verifikasi dua langkah untuk admin belum tersedia.</p></div>
@endsection
