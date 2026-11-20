import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { BulkBar, SelectAll, SelectRow, useSelection } from '@/components/bulk-select';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDate, fmtDateTime } from '@/lib/format';
import type { Paginated } from '@/types';

type Row = {
    id: number; amount: number; status: 'unpaid' | 'paid' | 'waived'; note: string | null; handled_at: string | null; handler: string | null;
    member: { id: number; member_no: string; name: string } | null; event: { id: number; title: string; starts_at: string } | null;
};
type Props = {
    fines: Paginated<Row>; summary: { unpaid_total: number; unpaid_count: number; paid_total: number };
    policy: { fine_enabled: boolean; fine_amount: number; fine_free_per_term: number }; term: string | null; filters: { status: string; q: string | null };
};

export const rupiah = (n: number) => `Rp${n.toLocaleString('id-ID')}`;
const statusLabel: Record<string, string> = { unpaid: 'Belum lunas', paid: 'Lunas', waived: 'Dibebaskan' };

export default function Fines({ fines, summary, policy, term, filters }: Props) {
    const [f, setF] = useState({ q: filters.q ?? '', status: filters.status });
    const sel = useSelection(fines.data.map((r) => r.id));
    const mark = (status: string) => ({ href: '/denda/tandai', data: { ids: sel.ids, status }, onSuccess: sel.clear });

    return (
        <AppLayout title="Denda">
            <Head title="Denda" />
            {!policy.fine_enabled && (
                <Alert><AlertDescription>Denda sedang <b>nonaktif</b>. Atur di <Link href="/pengaturan" className="underline">Pengaturan → Organisasi → Kebijakan denda</Link>.</AlertDescription></Alert>
            )}
            <div className="grid gap-3 sm:grid-cols-3">
                <Card><CardHeader><CardDescription>Belum lunas{term ? ` · ${term}` : ''}</CardDescription><CardTitle className="text-2xl tabular-nums">{rupiah(summary.unpaid_total)}</CardTitle><CardDescription>{summary.unpaid_count} denda</CardDescription></CardHeader></Card>
                <Card><CardHeader><CardDescription>Sudah lunas</CardDescription><CardTitle className="text-2xl tabular-nums">{rupiah(summary.paid_total)}</CardTitle></CardHeader></Card>
                <Card><CardHeader><CardDescription>Kebijakan</CardDescription><CardTitle className="text-base">{policy.fine_enabled ? `${rupiah(policy.fine_amount)} per absen QR` : 'Nonaktif'}</CardTitle><CardDescription>{policy.fine_free_per_term ? `${policy.fine_free_per_term}× QR gratis per periode` : 'Tanpa jatah gratis'}</CardDescription></CardHeader></Card>
            </div>
            <p className="text-sm text-muted-foreground">Denda tercatat otomatis saat anggota yang punya kartu NFC aktif absen memakai QR (kartu tidak dibawa). Belum punya kartu atau kartu dilapor hilang → tidak didenda.</p>
            <FilterBar url="/denda" values={f} search={f.q} onSearch={(q) => setF({ ...f, q })} placeholder="Cari anggota">
                <NativeSelect value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })} aria-label="Status">
                    <NativeSelectOption value="unpaid">Belum lunas</NativeSelectOption>
                    <NativeSelectOption value="paid">Lunas</NativeSelectOption>
                    <NativeSelectOption value="waived">Dibebaskan</NativeSelectOption>
                    <NativeSelectOption value="semua">Semua</NativeSelectOption>
                </NativeSelect>
            </FilterBar>
            <DataTable head={[<SelectAll key="all" sel={sel} />, 'Anggota', 'Kegiatan', 'Nominal', 'Status', 'Catatan']} count={fines.data.length} empty="Tidak ada denda.">
                {fines.data.map((r) => (
                    <TableRow key={r.id} data-state={sel.has(r.id) ? 'selected' : undefined}>
                        <TableCell className="w-8"><SelectRow sel={sel} id={r.id} label={r.member?.name ?? 'denda'} /></TableCell>
                        <TableCell>{r.member ? <Link href={`/anggota/${r.member.id}`} className="font-medium hover:underline">{r.member.name}</Link> : '—'}<div className="text-xs text-muted-foreground">{r.member?.member_no}</div></TableCell>
                        <TableCell>{r.event?.title ?? '—'}<div className="text-xs text-muted-foreground">{fmtDate(r.event?.starts_at)}</div></TableCell>
                        <TableCell className="tabular-nums">{rupiah(r.amount)}</TableCell>
                        <TableCell><StatusBadge status={r.status === 'unpaid' ? 'pending' : r.status === 'paid' ? 'approved' : 'closed'} label={statusLabel[r.status]} />{r.handler && <div className="text-xs text-muted-foreground">{r.handler} · {fmtDateTime(r.handled_at)}</div>}</TableCell>
                        <TableCell className="max-w-xs whitespace-normal text-sm">{r.note ?? '—'}</TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <BulkBar sel={sel}>
                <ConfirmAction size="sm" variant="default" title={`Tandai ${sel.count} denda lunas?`} description="Dicatat beserta nama Anda dan waktunya." {...mark('paid')} confirmLabel="Tandai lunas">Tandai lunas</ConfirmAction>
                <ConfirmAction size="sm" title={`Bebaskan ${sel.count} denda?`} description="Mis. kartu sedang dicetak ulang atau alasan lain yang diterima pengurus." {...mark('waived')} confirmLabel="Bebaskan">Bebaskan</ConfirmAction>
                {filters.status !== 'unpaid' && <ConfirmAction size="sm" title={`Kembalikan ${sel.count} denda ke belum lunas?`} description="Membatalkan tanda lunas/bebas." {...mark('unpaid')} confirmLabel="Kembalikan">Belum lunas</ConfirmAction>}
            </BulkBar>
            <Pager page={fines} />
        </AppLayout>
    );
}
