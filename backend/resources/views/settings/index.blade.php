@extends('layouts.app', ['title' => 'Pengaturan'])
@section('content')
<h1>Pengaturan</h1>
@include('partials.tabs', ['tabs' => ['organisasi' => 'Organisasi', 'periode' => 'Periode', 'divisi' => 'Divisi'], 'current' => $tab])
@if ($tab === 'organisasi')
    <form method="post" action="{{ route('settings.organization') }}" class="card">@csrf @method('put')
        <label>Nama organisasi</label><input name="name" value="{{ old('name', $org->name) }}" required style="width:100%;max-width:400px">
        <p class="muted">Kode: {{ $org->slug }}</p><button>Simpan</button>
    </form>
@elseif ($tab === 'periode')
    <table><tr><th>Periode</th><th>Mulai</th><th>Selesai</th><th>Status</th><th></th></tr>
        @foreach ($terms as $t)
            <tr><td>{{ $t->name }}</td><td>{{ $t->starts_on->format('d M Y') }}</td><td>{{ $t->ends_on->format('d M Y') }}</td>
                <td>@if ($t->is_current)<span class="pill active">berjalan</span>@endif</td>
                <td>@unless ($t->is_current)<form method="post" action="{{ route('settings.terms.current', $t) }}" onsubmit="return confirm('Jadikan {{ $t->name }} periode berjalan? Rekap & keanggotaan baru akan mengikuti periode ini.')">@csrf<button class="btn-secondary">Jadikan berjalan</button></form>@endunless</td></tr>
        @endforeach
    </table>
    <form method="post" action="{{ route('settings.terms.store') }}" class="card" style="margin-top:12px">@csrf
        <div class="form-row"><div><label>Nama periode</label><input name="name" placeholder="2027/2028" required></div>
            <div><label>Mulai</label><input type="date" name="starts_on" required></div><div><label>Selesai</label><input type="date" name="ends_on" required></div></div>
        <p><button>Tambah Periode</button></p>
    </form>
@else
    <table><tr><th>Divisi</th><th>Keanggotaan</th><th>Ganti nama</th></tr>
        @foreach ($divisions as $d)
            <tr><td>{{ $d->name }}</td><td>{{ $d->memberships_count }}</td>
                <td><form method="post" action="{{ route('settings.divisions.update', $d) }}" class="inline">@csrf @method('put')<input name="name" value="{{ $d->name }}" required><button class="btn-secondary">Simpan</button></form></td></tr>
        @endforeach
    </table>
    <form method="post" action="{{ route('settings.divisions.store') }}" class="card" style="margin-top:12px">@csrf
        <label>Divisi baru</label><input name="name" required> <button>Tambah</button>
    </form>
@endif
@endsection
