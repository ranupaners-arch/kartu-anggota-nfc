@extends('layouts.app', ['title' => 'Log Aktivitas'])
@section('content')
<h1>Log Aktivitas</h1>
<form method="get" class="toolbar">
    <input name="action" value="{{ request('action') }}" placeholder="aksi, mis. card. atau excuse">
    <select name="user_id"><option value="">Semua pengguna</option>@foreach ($users as $u)<option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->name }}</option>@endforeach</select>
    <input type="date" name="from" value="{{ request('from') }}"> – <input type="date" name="to" value="{{ request('to') }}">
    <button class="btn-secondary">Terapkan</button>
</form>
<table><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Objek</th><th>Detail</th><th>IP</th></tr>
    @forelse ($logs as $l)
        <tr><td>{{ $l->created_at?->format('d M Y H:i:s') }}</td><td>{{ $users[$l->user_id]->name ?? '—' }}</td><td><code>{{ $l->action }}</code></td>
            <td>{{ $l->entity }} #{{ $l->entity_id }}</td><td class="muted"><small>{{ $l->payload ? json_encode($l->payload, JSON_UNESCAPED_UNICODE) : '' }}</small></td><td>{{ $l->ip }}</td></tr>
    @empty
        <tr><td colspan="6" class="muted">Tidak ada catatan.</td></tr>
    @endforelse
</table>
{{ $logs->links() }}
@endsection
