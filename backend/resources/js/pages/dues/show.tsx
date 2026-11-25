import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { BulkBar, SelectAll, SelectRow, useSelection } from '@/components/bulk-select';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
import { PageTabs } from '@/components/page-tabs';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { TableCell, TableRow } from '@/components/ui/table';
import FinanceLayout from '@/layouts/finance-layout';
import { fmtDateTime } from '@/lib/format';
import type { Paginated } from '@/types';

type Status = 'unpaid' | 'paid' | 'waived';
type M = { member_no: string; name: string } | null;
type Item = { id: number; status: Status; member: M; note: string | null; changed_by: string | null; changed_at: string | null };
type Change = { id: number; from: Status; to: Status; note: string | null; at: string; by: string | null; member: M };
type Props = {
    due: { id: number; title: string; amount: number; due_date: string | null; cancelled_at: string | null; cancel_reason: string | null };
    summary: { unpaid: number; paid: number; waived: number; paid_total: number; unpaid_total: number };
    tab: string; filters: { q: string | null; status: string }; canManage: boolean;
    items: Paginated<Item> | null; changes: Paginated<Change> | null;
};

const rupiah = (n: number) => `Rp${n.toLocaleString('id-ID')}`;
const label: Record<Status, string> = { unpaid: 'Belum lunas', paid: 'Lunas', waived: 'Dibebaskan' };
const tone: Record<Status, string> = { unpaid: 'pending', paid: 'approved', waived: 'closed' };

function Items({ due, items, filters, canManage }: { due: Props['due']; items: Paginated<Item>; filters: Props['filters']; canManage: boolean }) {
    const [f, setF] = useState({ q: filters.q ?? '', status: filters.status });
    const [note, setNote] = useState('');
    const sel = useSelection(items.data.map((i) => i.id));
    const manage = canManage && !due.cancelled_at;
    const mark = (status: Status) => ({ href: `/iuran/${due.id}/tandai`, data: { ids: sel.ids, status, note: note || null }, onSuccess: () => { sel.clear(); setNote(''); } });
    return (
        <>
            <FilterBar url={`/iuran/${due.id}`} values={f} search={f.q} onSearch={(q) => setF({ ...f, q })} placeholder="Cari anggota">
                <NativeSelect value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })} aria-label="Status">
                    <NativeSelectOption value="semua">Semua</NativeSelectOption>
                    {Object.entries(label).map(([v, l]) => <NativeSelectOption key={v} value={v}>{l}</NativeSelectOption>)}
                </NativeSelect>
            </FilterBar>
            <DataTable head={[...(manage ? [<SelectAll key="all" sel={sel} />] : []), 'Anggota', 'Status', 'Catatan']} count={items.data.length} empty="Tidak ada tagihan.">
                {items.data.map((i) => (
                    <TableRow key={i.id} data-state={sel.has(i.id) ? 'selected' : undefined}>
                        {manage && <TableCell className="w-8"><SelectRow sel={sel} id={i.id} label={i.member?.name ?? 'tagihan'} /></TableCell>}
                        <TableCell>{i.member?.name ?? '-'}<div className="text-xs text-muted-foreground">{i.member?.member_no}</div></TableCell>
                        <TableCell><StatusBadge status={tone[i.status]} label={label[i.status]} />{i.changed_at && <div className="text-xs text-muted-foreground">{i.changed_by ?? '-'} · {fmtDateTime(i.changed_at)}</div>}</TableCell>
                        <TableCell className="max-w-xs whitespace-normal text-sm">{i.note ?? '-'}</TableCell>
                    </TableRow>
                ))}
            </DataTable>
            {manage && (
                <BulkBar sel={sel}>
                    <Input className="h-8 w-48" maxLength={300} placeholder="Catatan / alasan" aria-label="Catatan atau alasan" value={note} onChange={(e) => setNote(e.target.value)} />
                    <ConfirmAction size="sm" variant="default" title={`Tandai ${sel.count} tagihan lunas?`} description="Dicatat beserta nama Anda dan waktunya." {...mark('paid')} confirmLabel="Tandai lunas">Lunas</ConfirmAction>
                    <ConfirmAction size="sm" title={`Bebaskan ${sel.count} tagihan?`} description="Alasan wajib diisi di kolom catatan." {...mark('waived')} confirmLabel="Bebaskan">Bebaskan</ConfirmAction>
                    <ConfirmAction size="sm" title={`Kembalikan ${sel.count} tagihan ke belum lunas?`} description="Alasan wajib diisi di kolom catatan. Riwayat lama tetap tersimpan." {...mark('unpaid')} confirmLabel="Kembalikan">Belum lunas</ConfirmAction>
                </BulkBar>
            )}
            <Pager page={items} />
        </>
    );
}

export default function DueShow({ due, summary, tab, filters, canManage, items, changes }: Props) {
    const [reason, setReason] = useState('');
    return (
        <FinanceLayout title={due.title} actions={
            <>
                <Button variant="outline" render={<a href={`/iuran/${due.id}/ekspor`} />}><Download /> CSV</Button>
                {canManage && !due.cancelled_at && <Button variant="outline" onClick={() => router.post(`/iuran/${due.id}/sinkron`, {}, { preserveScroll: true })}>Tambah anggota baru</Button>}
            </>
        }>
            <Head title={due.title} />
            {due.cancelled_at && <Alert><AlertDescription>Tagihan dibatalkan: {due.cancel_reason}</AlertDescription></Alert>}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Card><CardHeader><CardDescription>Nominal</CardDescription><CardTitle className="text-lg sm:text-2xl tabular-nums">{rupiah(due.amount)}</CardTitle></CardHeader></Card>
                <Card><CardHeader><CardDescription>Lunas ({summary.paid})</CardDescription><CardTitle className="text-lg sm:text-2xl tabular-nums">{rupiah(summary.paid_total)}</CardTitle></CardHeader></Card>
                <Card><CardHeader><CardDescription>Belum lunas ({summary.unpaid})</CardDescription><CardTitle className="text-lg sm:text-2xl tabular-nums">{rupiah(summary.unpaid_total)}</CardTitle></CardHeader></Card>
                <Card><CardHeader><CardDescription>Dibebaskan</CardDescription><CardTitle className="text-lg sm:text-2xl tabular-nums">{summary.waived}</CardTitle></CardHeader></Card>
            </div>
            <PageTabs tabs={{ tagihan: 'Tagihan', riwayat: 'Riwayat Perubahan' }} current={tab} />
            {items && <Items due={due} items={items} filters={filters} canManage={canManage} />}
            {changes && (
                <>
                    <DataTable head={['Waktu', 'Anggota', 'Perubahan', 'Oleh', 'Catatan']} count={changes.data.length} empty="Belum ada perubahan.">
                        {changes.data.map((c) => (
                            <TableRow key={c.id}>
                                <TableCell>{fmtDateTime(c.at)}</TableCell>
                                <TableCell>{c.member?.name ?? '-'}<div className="text-xs text-muted-foreground">{c.member?.member_no}</div></TableCell>
                                <TableCell>{label[c.from]} → {label[c.to]}</TableCell><TableCell>{c.by ?? '-'}</TableCell>
                                <TableCell className="max-w-xs whitespace-normal text-sm">{c.note ?? '-'}</TableCell>
                            </TableRow>
                        ))}
                    </DataTable>
                    <Pager page={changes} />
                </>
            )}
            {tab === 'tagihan' && canManage && !due.cancelled_at && summary.paid === 0 && (
                <div className="flex flex-wrap items-center gap-2 border-t pt-4">
                    <Input className="h-8 w-64" maxLength={300} placeholder="Alasan pembatalan" aria-label="Alasan pembatalan" value={reason} onChange={(e) => setReason(e.target.value)} />
                    <ConfirmAction size="sm" destructive title="Batalkan tagihan ini?" description="Tagihan tidak lagi tampil di anggota. Catatan tetap tersimpan." href={`/iuran/${due.id}/batal`} data={{ reason }} confirmLabel="Batalkan tagihan">Batalkan tagihan</ConfirmAction>
                </div>
            )}
        </FinanceLayout>
    );
}
