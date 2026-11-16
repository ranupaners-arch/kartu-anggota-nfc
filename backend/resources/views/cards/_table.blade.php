<table>
    <tr>@if ($showMember ?? true)<th>Anggota</th>@endif<th>ID Kartu</th><th>Status</th><th>Diterbitkan</th><th>Dicabut</th><th>Tindakan</th></tr>
    @forelse ($cards as $c)
        <tr>
            @if ($showMember ?? true)<td>@if ($c->member)<a href="{{ route('members.show', $c->member) }}">{{ $c->member->member_no }} {{ $c->member->name }}</a>@else<span class="muted">belum ditautkan</span>@endif</td>@endif
            <td><code>{{ \Illuminate\Support\Str::limit($c->public_id, 10, '…') }}</code></td>
            <td><span class="pill {{ $c->status }}">{{ $c->status }}</span></td>
            <td>{{ $c->issued_at?->format('d M Y') }}</td><td>{{ $c->revoked_at?->format('d M Y') }}</td>
            <td>@if ($c->status === 'active')
                <form method="post" action="{{ route('cards.block', $c) }}" class="inline" onsubmit="return confirm('Kartu tidak bisa dipakai absen lagi. Lanjutkan?')">@csrf
                    <button name="status" value="blocked" class="btn-secondary">Blokir</button><button name="status" value="lost" class="btn-danger">Hilang</button></form>
            @endif</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">Tidak ada kartu.</td></tr>
    @endforelse
</table>
