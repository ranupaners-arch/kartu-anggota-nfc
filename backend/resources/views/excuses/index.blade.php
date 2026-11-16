@extends('layouts.app', ['title' => 'Pengajuan Izin'])
@section('content')
<h1>Pengajuan Izin</h1>
@include('partials.tabs', ['tabs' => ['menunggu' => 'Menunggu', 'riwayat' => 'Riwayat'], 'current' => $tab])
<table>
    <tr><th>Anggota</th><th>Kegiatan</th><th>Jenis</th><th>Alasan</th><th>Bukti</th><th>{{ $tab === 'menunggu' ? 'Tindakan' : 'Keputusan' }}</th></tr>
    @forelse ($excuses as $x)
        <tr>
            <td>{{ $x->member?->member_no }} {{ $x->member?->name }}</td>
            <td>{{ $x->event?->title }}<div class="muted">{{ $x->event?->starts_at?->format('d M Y') }}</div></td>
            <td><span class="pill {{ $x->type }}">{{ $x->type }}</span></td>
            <td>{{ $x->reason }}</td>
            <td>@if ($x->attachment_path)<a href="{{ route('excuses.attachment', $x) }}" target="_blank">lihat</a>@else<span class="muted">—</span>@endif</td>
            <td>
                @if ($x->status === 'pending')
                    <form method="post" action="{{ route('excuses.review', $x) }}">@csrf @method('patch')
                        <input name="note" placeholder="catatan (opsional)" size="14">
                        <button name="status" value="approved">Setujui</button>
                        <button name="status" value="rejected" class="btn-danger">Tolak</button>
                    </form>
                @else
                    <span class="pill {{ $x->status }}">{{ $x->status === 'approved' ? 'disetujui' : 'ditolak' }}</span>
                    <div class="muted">{{ $x->review_note }}</div>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">Tidak ada pengajuan.</td></tr>
    @endforelse
</table>
{{ $excuses->links() }}
@endsection
