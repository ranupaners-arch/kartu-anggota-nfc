@extends('layouts.app', ['title' => $event->exists ? 'Ubah Kegiatan' : 'Buat Kegiatan'])
@php $dt = fn ($f) => old($f, $event->$f?->format('Y-m-d\TH:i')); @endphp
@section('content')
<h1>{{ $event->exists ? 'Ubah Kegiatan' : 'Buat Kegiatan' }}</h1>
<form method="post" action="{{ $event->exists ? route('events.update', $event) : route('events.store') }}" class="card">
    @csrf @if ($event->exists) @method('put') @endif
    <div class="form-row">
        <div><label>Judul</label><input name="title" value="{{ old('title', $event->title) }}" required></div>
        <div><label>Tipe</label><input name="type" value="{{ old('type', $event->type) }}"></div>
        <div><label>Lokasi</label><input name="location" value="{{ old('location', $event->location) }}"></div>
    </div>
    <div class="form-row">
        <div><label>Mulai</label><input type="datetime-local" name="starts_at" value="{{ $dt('starts_at') }}" required></div>
        <div><label>Selesai</label><input type="datetime-local" name="ends_at" value="{{ $dt('ends_at') }}" required></div>
    </div>
    <div class="form-row">
        <div><label>Check-in dibuka</label><input type="datetime-local" name="checkin_opens_at" value="{{ $dt('checkin_opens_at') }}" required></div>
        <div><label>Dianggap telat setelah</label><input type="datetime-local" name="late_after" value="{{ $dt('late_after') }}" required></div>
        <div><label>Check-in ditutup</label><input type="datetime-local" name="checkin_closes_at" value="{{ $dt('checkin_closes_at') }}" required></div>
    </div>
    @if (! $event->exists)
        @if (auth()->user()->isSuperAdmin())
            <label>Milik divisi (kosong = kegiatan umum)</label>
            <select name="division_id"><option value="">—</option>@foreach ($divisions as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
        @endif
        <label>Target peserta per divisi (kosong = semua anggota aktif)</label>
        @foreach ($divisions as $d)
            <label style="font-weight:400;display:inline-block;margin-right:12px"><input type="checkbox" name="target_division_ids[]" value="{{ $d->id }}" @checked(in_array($d->id, old('target_division_ids', [])))> {{ $d->name }}</label>
        @endforeach
    @endif
    <label style="font-weight:400"><input type="checkbox" name="is_mandatory" value="1" @checked(old('is_mandatory', $event->is_mandatory))> Wajib hadir (yang tidak hadir ditandai Alpa saat ditutup)</label>
    @if ($event->exists && $event->checkin_opens_at?->lte(now()))
        <label>Alasan perubahan (wajib bila jendela check-in diubah, karena kegiatan sudah berjalan)</label>
        <input name="reason" value="{{ old('reason') }}" style="width:100%">
    @endif
    <p><button>Simpan</button> <a href="{{ $event->exists ? route('events.show', $event) : route('events.index') }}">Batal</a></p>
</form>
@endsection
