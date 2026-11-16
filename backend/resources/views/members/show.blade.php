@extends('layouts.app', ['title' => $member->name])
@section('content')
<div class="toolbar"><h1 style="margin:0">{{ $member->name }}</h1><span class="muted">{{ $member->member_no }}</span><span class="spacer"></span>
    <a class="btn btn-secondary" href="{{ route('members.edit', $member) }}">Ubah</a>
    <form method="post" action="{{ route('members.destroy', $member) }}" class="inline" onsubmit="return confirm('Hapus anggota ini? Kartu aktifnya akan diblokir.')">@csrf @method('delete')<button class="btn-danger">Hapus</button></form>
</div>
@include('partials.tabs', ['tabs' => ['profil' => 'Profil', 'riwayat' => 'Riwayat Hadir', 'kartu' => 'Kartu'], 'current' => $tab])
@if ($tab === 'profil')
    <div class="card" style="display:flex;gap:16px">
        <div>@if ($member->photo_path)<img src="{{ route('members.photo', $member) }}" alt="Foto" width="120">@else<div class="muted" style="width:120px">Belum ada foto</div>@endif
            <form method="post" action="{{ route('members.photo.upload', $member) }}" enctype="multipart/form-data">@csrf<input type="file" name="photo" accept="image/*" required style="width:120px"><button class="btn-secondary">Unggah</button></form></div>
        <div>
            <div>Email: {{ $member->email ?: '—' }}</div><div>Telepon: {{ $member->phone ?: '—' }}</div><div>Angkatan: {{ $member->cohort ?: '—' }}</div>
            <h2>Keanggotaan</h2>
            <table><tr><th>Periode</th><th>Divisi</th><th>Jabatan</th><th>Status</th></tr>
                @foreach ($member->memberships->sortByDesc('term_id') as $ms)
                    <tr><td>{{ $ms->term?->name }}</td><td>{{ $ms->division?->name }}</td><td>{{ $ms->role }}</td><td><span class="pill {{ $ms->status }}">{{ $ms->status }}</span></td></tr>
                @endforeach
            </table>
        </div>
    </div>
@elseif ($tab === 'riwayat')
    <table><tr><th>Kegiatan</th><th>Tanggal</th><th>Status</th><th>Sumber</th></tr>
        @forelse ($attendances as $a)
            <tr><td>{{ $a->event?->title }}</td><td>{{ $a->event?->starts_at?->format('d M Y') }}</td>
                <td><span class="pill {{ $a->status }}">{{ $a->voided_at ? 'dibatalkan' : $a->status }}</span></td><td>{{ $a->source }}</td></tr>
        @empty
            <tr><td colspan="4" class="muted">Belum ada riwayat.</td></tr>
        @endforelse
    </table>
    {{ $attendances->links() }}
@else
    <p class="muted">Kartu baru diterbitkan lewat app petugas (mode Issue). Menerbitkan kartu baru otomatis memblokir kartu lama.</p>
    @include('cards._table', ['cards' => $member->cards->sortByDesc('issued_at'), 'showMember' => false])
@endif
@endsection
