@extends('layouts.app', ['title' => $event->title])
@section('content')
<div class="toolbar">
    <h1 style="margin:0">{{ $event->title }}</h1><span class="spacer"></span>
    <a class="btn btn-secondary" href="{{ route('events.edit', $event) }}">Ubah</a>
    @unless ($event->closed_at)
        <form method="post" action="{{ route('events.close', $event) }}" class="inline" onsubmit="return confirm('Tutup kegiatan? Anggota wajib yang tidak hadir akan ditandai Alpa.')">@csrf<button>Tutup Kegiatan</button></form>
    @endunless
</div>
<p class="muted">{{ $event->starts_at->format('d M Y H:i') }}–{{ $event->ends_at->format('H:i') }} · {{ $event->location ?: 'tanpa lokasi' }} ·
    check-in {{ $event->checkin_opens_at->format('H:i') }}–{{ $event->checkin_closes_at->format('H:i') }}, telat setelah {{ $event->late_after->format('H:i') }}
    · {{ $event->closed_at ? 'ditutup '.$event->closed_at->format('d M H:i') : 'terbuka' }}</p>
@include('partials.tabs', ['tabs' => ['ringkasan' => 'Ringkasan', 'hadir' => 'Daftar Hadir', 'sesi' => 'Sesi', 'target' => 'Target Peserta'], 'current' => $tab])

@if ($tab === 'ringkasan')
    <div class="stats">
        <div class="card stat"><div class="n">{{ $report['summary']['total'] }}</div><div class="l">Peserta</div></div>
        @foreach ($report['summary']['by_status'] as $s => $n)
            <div class="card stat"><div class="n">{{ $n }}</div><div class="l">{{ str_replace('_', ' ', ucfirst($s)) }}</div></div>
        @endforeach
        <div class="card stat"><div class="n">{{ $report['summary']['present_rate'] ?? '–' }}%</div><div class="l">Kehadiran</div></div>
    </div>
    <div class="card">
        <b>Ekspor rekap kegiatan:</b>
        @foreach (['xlsx' => 'Excel', 'pdf' => 'PDF'] as $f => $l)
            <form method="post" action="{{ route('exports.store') }}" class="inline">@csrf
                <input type="hidden" name="report" value="event"><input type="hidden" name="event_id" value="{{ $event->id }}"><input type="hidden" name="format" value="{{ $f }}">
                <button class="btn-secondary">{{ $l }}</button></form>
        @endforeach
        <span class="muted">Hasilnya ada di Rekap &amp; Ekspor → Hasil Ekspor.</span>
    </div>
    @if (! $event->attendances()->exists())
        <form method="post" action="{{ route('events.destroy', $event) }}" onsubmit="return confirm('Hapus kegiatan ini?')">@csrf @method('delete')<button class="btn-danger">Hapus Kegiatan</button></form>
    @endif
@elseif ($tab === 'hadir')
    <table>
        <tr><th>No. Anggota</th><th>Nama</th><th>Divisi</th><th>Status</th><th>Waktu</th><th>Koreksi</th></tr>
        @foreach ($report['rows'] as $row)
            <tr>
                <td>{{ $row['member_no'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['division'] }}</td>
                <td><span class="pill {{ $row['status'] }}">{{ str_replace('_', ' ', $row['status']) }}</span>@if ($row['needs_review']) <span class="pill pending">perlu ditinjau</span>@endif</td>
                <td>{{ $row['scanned_at'] ? \Illuminate\Support\Carbon::parse($row['scanned_at'])->format('H:i') : '' }} <span class="muted">{{ $row['source'] }}</span></td>
                <td>
                    @if ($row['attendance_id'])
                        <form method="post" action="{{ route('attendances.correct', $row['attendance_id']) }}" class="inline">@csrf @method('patch')
                            <select name="status">@foreach (['hadir', 'telat', 'izin', 'sakit', 'alpa', 'void'] as $s)<option value="{{ $s }}" @selected($s === $row['status'])>{{ $s === 'void' ? 'batalkan' : $s }}</option>@endforeach</select>
                            <input name="reason" placeholder="alasan" required size="12"><button class="btn-secondary">Simpan</button>
                        </form>
                    @else<span class="muted">—</span>@endif
                </td>
            </tr>
        @endforeach
    </table>
@elseif ($tab === 'sesi')
    <table>
        <tr><th>Sesi</th><th>Mulai</th><th>Selesai</th></tr>
        @forelse ($event->sessions as $s)
            <tr><td>{{ $s->title }}@if ($loop->first) <span class="pill">utama</span>@endif</td><td>{{ $s->starts_at?->format('d M H:i') }}</td><td>{{ $s->ends_at?->format('d M H:i') }}</td></tr>
        @empty
            <tr><td colspan="3" class="muted">Belum ada sesi.</td></tr>
        @endforelse
    </table>
    <form method="post" action="{{ route('events.sessions.store', $event) }}" class="card" style="margin-top:12px">@csrf
        <div class="form-row">
            <div><label>Judul sesi</label><input name="title" required></div>
            <div><label>Mulai</label><input type="datetime-local" name="starts_at"></div>
            <div><label>Selesai</label><input type="datetime-local" name="ends_at"></div>
        </div>
        <p><button>Tambah Sesi</button> <span class="muted">Absensi per sesi dari app petugas menyusul.</span></p>
    </form>
@else
    <form method="post" action="{{ route('events.targets', $event) }}" class="card">@csrf @method('put')
        <p class="muted">Kosongkan semua untuk menjadikan target seluruh anggota aktif periode ini.</p>
        <label>Divisi</label>
        @foreach ($divisions as $d)
            <label style="font-weight:400;display:inline-block;margin-right:12px"><input type="checkbox" name="division_ids[]" value="{{ $d->id }}" @checked($event->targets->pluck('division_id')->contains($d->id))> {{ $d->name }}</label>
        @endforeach
        <label>Anggota tambahan (nomor anggota, pisahkan dengan koma atau baris baru)</label>
        <textarea name="member_nos" rows="3" style="width:100%">{{ old('member_nos', $targetMembers->pluck('member_no')->implode(', ')) }}</textarea>
        <p><button>Simpan Target</button></p>
    </form>
@endif
@endsection
