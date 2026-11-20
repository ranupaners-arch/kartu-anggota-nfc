import { Link } from '@inertiajs/react';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/data-table';
import { StatusBadge } from '@/components/status-badge';
import { TableCell, TableRow } from '@/components/ui/table';
import { fmtDate } from '@/lib/format';

export type CardRow = { id: number; public_id: string; status: string; issued_at: string | null; revoked_at: string | null; issued_by?: string | null; member?: { id: number; member_no: string; name: string } | null };

export function CardsTable({ cards, showMember = true }: { cards: CardRow[]; showMember?: boolean }) {
    // Kolom "Oleh" (pengurus / anggota sendiri) hanya bila datanya tersedia (detail anggota).
    const showIssuer = cards.some((c) => c.issued_by !== undefined);
    return (
        <DataTable head={[...(showMember ? ['Anggota'] : []), 'ID Kartu', 'Status', 'Diterbitkan', ...(showIssuer ? ['Oleh'] : []), 'Dicabut', 'Tindakan']} count={cards.length} empty="Tidak ada kartu.">
            {cards.map((c) => (
                <TableRow key={c.id}>
                    {showMember && <TableCell>{c.member ? <Link href={`/anggota/${c.member.id}`} className="hover:underline">{c.member.name}<span className="ml-1 text-xs text-muted-foreground">{c.member.member_no}</span></Link> : <span className="text-muted-foreground">Belum ditautkan</span>}</TableCell>}
                    <TableCell><code className="text-xs">{c.public_id.slice(0, 10)}…</code></TableCell>
                    <TableCell><StatusBadge status={c.status} /></TableCell>
                    <TableCell>{fmtDate(c.issued_at)}</TableCell>
                    {showIssuer && <TableCell>{c.issued_by ?? '—'}</TableCell>}
                    <TableCell>{fmtDate(c.revoked_at)}</TableCell>
                    <TableCell className="space-x-1.5">
                        {c.status === 'active' && (
                            <>
                                <ConfirmAction size="sm" title="Blokir kartu?" description="Kartu tidak bisa dipakai absen lagi. Terbitkan kartu baru lewat app petugas." href={`/kartu/${c.id}/blokir`} data={{ status: 'blocked' }} confirmLabel="Blokir">Blokir</ConfirmAction>
                                <ConfirmAction size="sm" destructive title="Tandai kartu hilang?" description="Kartu tidak bisa dipakai absen lagi, dan percobaan memakainya akan tercatat." href={`/kartu/${c.id}/blokir`} data={{ status: 'lost' }} confirmLabel="Tandai hilang">Hilang</ConfirmAction>
                            </>
                        )}
                    </TableCell>
                </TableRow>
            ))}
        </DataTable>
    );
}
