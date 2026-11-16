@extends('layouts.app', ['title' => 'Perlu Ditinjau'])
@section('content')
<h1>Perlu Ditinjau</h1>
@include('partials.tabs', ['tabs' => ['offline' => 'Konflik Offline', 'override' => 'Override', 'anomali' => 'Anomali'], 'current' => $tab])
@if ($tab === 'anomali')
    <p class="muted">Kartu yang sama di-tap di kegiatan/perangkat berbeda dalam &lt; 60 detik. Periksa kemungkinan kartu digandakan.</p>
    <table>
        <tr><th>Waktu</th><th>Kartu</th><th>Detail</th></tr>
        @forelse ($items as $log)
            <tr><td>{{ $log->created_at?->format('d M Y H:i:s') }}</td><td>#{{ $log->entity_id }}</td><td class="muted">kegiatan {{ $log->payload['event_id'] ?? '?' }}, perangkat {{ $log->payload['device_id'] ?? '?' }}</td></tr>
        @empty
            <tr><td colspan="3" class="muted">Tidak ada anomali.</td></tr>
        @endforelse
    </table>
@else
    <p class="muted">{{ $tab === 'offline'
        ? 'Scan offline yang bermasalah saat disinkron (mis. kartu diblokir setelah scan). Terima bila sah, batalkan bila tidak.'
        : 'Scan di luar jendela check-in yang di-override petugas dengan alasan.' }}</p>
    <table>
        <tr><th>Anggota</th><th>Kegiatan</th><th>Status</th><th>Waktu scan</th><th>Catatan</th><th>Tindakan</th></tr>
        @forelse ($items as $a)
            <tr>
                <td>{{ $a->member?->member_no }} {{ $a->member?->name }}</td>
                <td>{{ $a->event?->title }}</td>
                <td><span class="pill {{ $a->status }}">{{ $a->status }}</span></td>
                <td>{{ $a->scanned_at?->format('d M H:i') }}</td>
                <td class="muted">{{ $a->review_reason }}</td>
                <td>
                    @if ($tab === 'offline')
                        <form method="post" action="{{ route('reviews.accept', $a) }}" class="inline">@csrf<input name="reason" placeholder="alasan" required size="10"><button>Terima</button></form>
                    @endif
                    <form method="post" action="{{ route('reviews.void', $a) }}" class="inline">@csrf<input name="reason" placeholder="alasan" required size="10"><button class="btn-danger">Batalkan</button></form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Tidak ada.</td></tr>
        @endforelse
    </table>
@endif
{{ $items->links() }}
@endsection
