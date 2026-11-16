@extends('layouts.app', ['title' => 'Impor Anggota'])
@section('content')
<h1>Impor Anggota dari CSV</h1>
@isset($preview)
    <div class="card">
        <b>Pratinjau:</b> {{ $preview['summary']['valid'] }} baris valid ({{ $preview['summary']['create'] }} baru, {{ $preview['summary']['update'] }} diperbarui),
        <span @class(['muted' => ! $preview['summary']['errors']])>{{ $preview['summary']['errors'] }} baris error</span>.
        Berlaku {{ $preview['expires_in_minutes'] }} menit.
    </div>
    @if ($preview['errors'])
        <h2>Baris error</h2>
        <table><tr><th>Baris</th><th>No. Anggota</th><th>Masalah</th></tr>
            @foreach ($preview['errors'] as $e)<tr><td>{{ $e['line'] }}</td><td>{{ $e['member_no'] }}</td><td>{{ implode(' ', $e['errors']) }}</td></tr>@endforeach
        </table>
    @endif
    <h2>Contoh data (10 pertama)</h2>
    <table><tr><th>Aksi</th><th>No.</th><th>Nama</th><th>Email</th><th>Angkatan</th><th>Status</th></tr>
        @foreach ($preview['sample'] as $s)<tr><td>{{ $s['action'] === 'create' ? 'baru' : 'perbarui' }}</td><td>{{ $s['member_no'] }}</td><td>{{ $s['name'] }}</td><td>{{ $s['email'] }}</td><td>{{ $s['cohort'] }}</td><td>{{ $s['status'] }}</td></tr>@endforeach
    </table>
    <form method="post" action="{{ route('members.import.commit') }}" class="card" style="margin-top:12px">@csrf
        <input type="hidden" name="token" value="{{ $preview['token'] }}">
        @if ($preview['errors'])<label style="font-weight:400"><input type="checkbox" name="skip_errors" value="1"> Lewati baris error dan simpan yang valid</label>@endif
        <button>Simpan {{ $preview['summary']['valid'] }} anggota</button> <a href="{{ route('members.import') }}">Unggah ulang</a>
    </form>
@else
    <div class="card">
        <p>Kolom wajib: <code>member_no</code>, <code>name</code>. Opsional: <code>email</code>, <code>phone</code>, <code>cohort</code>, <code>division</code> (nama divisi), <code>status</code> (applicant/active/inactive/alumni).
            Pemisah koma atau titik koma (format Excel Indonesia). Anggota yang nomornya sudah ada akan diperbarui.</p>
        <form method="post" action="{{ route('members.import.preview') }}" enctype="multipart/form-data">@csrf
            <input type="file" name="file" accept=".csv,text/csv" required> <button>Pratinjau</button>
        </form>
    </div>
@endisset
@endsection
