import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download, Pencil } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
import { FormField } from '@/components/form-field';
import { PageTabs } from '@/components/page-tabs';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDate, fmtDateTime, toLocalInput } from '@/lib/format';
import type { Paginated } from '@/types';

type Item = { id: number; name: string; code: string | null; quantity: number; location: string | null; note: string | null; archived_at: string | null; on_loan: number; has_loans: boolean };
type Loan = {
    id: number; item: string; quantity: number; member: { id: number; member_no: string; name: string } | null; lent_at: string; due_on: string; overdue: boolean; note: string | null; lender: string | null;
    returned_at: string | null; condition: string | null; return_note: string | null; receiver: string | null;
};
type Filters = { q: string | null; status: string | null };
type Props = { tab: string; filters: Filters; canManage: boolean; overdue: number; items: Paginated<Item> | null; available: { id: number; name: string; free: number }[] | null; loans: Paginated<Loan> | null };

const conditions: Record<string, string> = { good: 'Baik', damaged: 'Rusak', lost: 'Hilang' };

function ItemForm({ item, onDone }: { item?: Item; onDone?: () => void }) {
    const form = useForm({ name: item?.name ?? '', code: item?.code ?? '', quantity: String(item?.quantity ?? 1), location: item?.location ?? '', note: item?.note ?? '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (item) form.put(`/inventaris/${item.id}`, { preserveScroll: true, onSuccess: onDone });
        else form.post('/inventaris', { preserveScroll: true, onSuccess: () => form.reset() });
    };
    return (
        <form onSubmit={submit} className="grid gap-4">
            <div className="grid items-start gap-4 sm:grid-cols-2">
                <FormField label="Nama barang" error={form.errors.name}><Input required maxLength={100} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></FormField>
                <FormField label="Jumlah" error={form.errors.quantity}><Input type="number" required min={0} max={100000} value={form.data.quantity} onChange={(e) => form.setData('quantity', e.target.value)} /></FormField>
                <FormField label="Kode (opsional)" error={form.errors.code}><Input maxLength={30} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} /></FormField>
                <FormField label="Lokasi (opsional)" error={form.errors.location}><Input maxLength={100} value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} /></FormField>
            </div>
            <FormField label="Catatan (opsional)" error={form.errors.note}><Input maxLength={300} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} /></FormField>
            {item
                ? <DialogFooter><Button type="submit" disabled={form.processing}>{form.processing ? 'Menyimpan…' : 'Simpan'}</Button></DialogFooter>
                : <div><Button type="submit" disabled={form.processing}>{form.processing ? 'Menyimpan…' : 'Tambah barang'}</Button></div>}
        </form>
    );
}

function Items({ items, canManage, filters }: { items: Paginated<Item>; canManage: boolean; filters: Filters }) {
    const [f, setF] = useState({ q: filters.q ?? '', status: filters.status ?? '' });
    const [editing, setEditing] = useState<Item | null>(null);
    return (
        <>
            {canManage && <Card className="max-w-3xl"><CardHeader><CardTitle>Tambah barang</CardTitle></CardHeader><CardContent><ItemForm /></CardContent></Card>}
            <FilterBar url="/inventaris" values={f} search={f.q} onSearch={(q) => setF({ ...f, q })} placeholder="Cari barang">
                <NativeSelect aria-label="Status barang" value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}><NativeSelectOption value="">Dipakai</NativeSelectOption><NativeSelectOption value="arsip">Arsip</NativeSelectOption></NativeSelect>
            </FilterBar>
            <DataTable head={['Barang', 'Lokasi', 'Jumlah', 'Tersedia', '']} count={items.data.length} empty="Belum ada barang.">
                {items.data.map((i) => (
                    <TableRow key={i.id}>
                        <TableCell className="max-w-xs whitespace-normal">{i.name}<div className="text-xs text-muted-foreground">{[i.code, i.note].filter(Boolean).join(' · ')}</div></TableCell>
                        <TableCell className="whitespace-normal">{i.location ?? '—'}</TableCell>
                        <TableCell className="tabular-nums">{i.quantity}</TableCell>
                        <TableCell className="tabular-nums">{i.quantity - i.on_loan}{i.on_loan > 0 && <span className="text-xs text-muted-foreground"> ({i.on_loan} dipinjam)</span>}</TableCell>
                        <TableCell>{canManage && (
                            <div className="flex flex-wrap justify-end gap-1.5">
                                {!i.archived_at && <Button size="sm" variant="outline" aria-label={`Ubah ${i.name}`} onClick={() => setEditing(i)}><Pencil /> Ubah</Button>}
                                {i.on_loan === 0 && <ConfirmAction size="sm" href={`/inventaris/${i.id}/arsip`} method="patch" data={{ archived: i.archived_at ? 0 : 1 }} title={i.archived_at ? `Pulihkan ${i.name}?` : `Arsipkan ${i.name}?`}
                                    description={i.archived_at ? 'Barang bisa dipinjam lagi.' : 'Barang tidak bisa dipinjam. Riwayatnya tetap ada.'} confirmLabel={i.archived_at ? 'Pulihkan' : 'Arsipkan'}>{i.archived_at ? 'Pulihkan' : 'Arsipkan'}</ConfirmAction>}
                                {!i.has_loans && <ConfirmAction size="sm" destructive href={`/inventaris/${i.id}`} method="delete" title={`Hapus ${i.name}?`} description="Barang ini belum pernah dipinjam dan akan dihapus permanen." confirmLabel="Hapus">Hapus</ConfirmAction>}
                            </div>
                        )}</TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <Pager page={items} />
            <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent>
                    <DialogHeader><DialogTitle>Ubah barang</DialogTitle><DialogDescription>{editing?.on_loan ? `Sedang dipinjam ${editing.on_loan}.` : 'Tidak sedang dipinjam.'}</DialogDescription></DialogHeader>
                    {editing && <ItemForm key={editing.id} item={editing} onDone={() => setEditing(null)} />}
                </DialogContent>
            </Dialog>
        </>
    );
}

function LendForm({ available }: { available: { id: number; name: string; free: number }[] }) {
    const today = toLocalInput(new Date().toISOString()).slice(0, 10);
    const form = useForm({ item_id: '', member_no: '', quantity: '1', due_on: '', note: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/inventaris/pinjam', { preserveScroll: true, onSuccess: () => form.reset() }); };
    return (
        <Card className="max-w-4xl">
            <CardHeader><CardTitle>Catat peminjaman</CardTitle><CardDescription>{available.length ? 'Peminjam melihat barang ini di app/portal dan diingatkan menjelang jatuh tempo.' : 'Tidak ada barang tersedia.'}</CardDescription></CardHeader>
            <CardContent>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid items-start gap-4 sm:grid-cols-4">
                        <FormField label="Barang" error={form.errors.item_id}>
                            <NativeSelect className="w-full" required value={form.data.item_id} onChange={(e) => form.setData('item_id', e.target.value)}>
                                <NativeSelectOption value="">Pilih barang</NativeSelectOption>
                                {available.map((i) => <NativeSelectOption key={i.id} value={i.id}>{i.name} — tersedia {i.free}</NativeSelectOption>)}
                            </NativeSelect>
                        </FormField>
                        <FormField label="No. anggota" error={form.errors.member_no}><Input required maxLength={50} value={form.data.member_no} onChange={(e) => form.setData('member_no', e.target.value)} /></FormField>
                        <FormField label="Jumlah" error={form.errors.quantity}><Input type="number" required min={1} value={form.data.quantity} onChange={(e) => form.setData('quantity', e.target.value)} /></FormField>
                        <FormField label="Jatuh tempo" error={form.errors.due_on}><Input type="date" required min={today} value={form.data.due_on} onChange={(e) => form.setData('due_on', e.target.value)} /></FormField>
                    </div>
                    <FormField label="Keterangan (opsional)" error={form.errors.note}><Input maxLength={300} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} /></FormField>
                    <div><Button type="submit" disabled={form.processing || available.length === 0}>{form.processing ? 'Menyimpan…' : 'Catat peminjaman'}</Button></div>
                </form>
            </CardContent>
        </Card>
    );
}

function ReturnForm({ id }: { id: number }) {
    const [condition, setCondition] = useState('good');
    const [note, setNote] = useState('');
    const submit = (e: FormEvent) => { e.preventDefault(); router.post(`/inventaris/pinjam/${id}/kembali`, { condition, note }, { preserveScroll: true }); };
    return (
        <form onSubmit={submit} className="flex flex-wrap items-center justify-end gap-1.5">
            <NativeSelect size="sm" aria-label="Kondisi saat kembali" value={condition} onChange={(e) => setCondition(e.target.value)}>{Object.entries(conditions).map(([k, l]) => <NativeSelectOption key={k} value={k}>{l}</NativeSelectOption>)}</NativeSelect>
            {condition !== 'good' && <Input className="h-7 w-32" placeholder="Keterangan" aria-label="Keterangan kondisi" required minLength={3} maxLength={300} value={note} onChange={(e) => setNote(e.target.value)} />}
            <Button size="sm" variant="outline" type="submit">{condition === 'lost' ? 'Catat hilang' : 'Terima kembali'}</Button>
        </form>
    );
}

function Loans({ loans, available, filters }: { loans: Paginated<Loan>; available: { id: number; name: string; free: number }[]; filters: Filters }) {
    const [f, setF] = useState({ q: filters.q ?? '', status: filters.status ?? '' });
    return (
        <>
            <LendForm available={available} />
            <FilterBar url="/inventaris" keep={{ tab: 'pinjaman' }} values={f} search={f.q} onSearch={(q) => setF({ ...f, q })} placeholder="Cari barang/anggota">
                <NativeSelect aria-label="Status peminjaman" value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}>
                    <NativeSelectOption value="">Semua</NativeSelectOption><NativeSelectOption value="aktif">Dipinjam</NativeSelectOption><NativeSelectOption value="terlambat">Terlambat</NativeSelectOption><NativeSelectOption value="selesai">Selesai</NativeSelectOption>
                </NativeSelect>
                <Button variant="outline" render={<a href="/inventaris/ekspor" />}><Download /> CSV</Button>
            </FilterBar>
            <DataTable head={['Barang', 'Peminjam', 'Dipinjam', 'Jatuh tempo', 'Status', '']} count={loans.data.length} empty="Belum ada peminjaman.">
                {loans.data.map((l) => (
                    <TableRow key={l.id}>
                        <TableCell className="max-w-xs whitespace-normal">{l.item}{l.quantity > 1 && ` (${l.quantity})`}{l.note && <div className="text-xs text-muted-foreground">{l.note}</div>}</TableCell>
                        <TableCell>{l.member ? <Link href={`/anggota/${l.member.id}`} className="font-medium hover:underline">{l.member.name}</Link> : '—'}<div className="text-xs text-muted-foreground">{l.member?.member_no}</div></TableCell>
                        <TableCell>{fmtDate(l.lent_at)}<div className="text-xs text-muted-foreground">{l.lender ?? '—'}</div></TableCell>
                        <TableCell>{fmtDate(l.due_on)}</TableCell>
                        <TableCell className="whitespace-normal">
                            <StatusBadge status={l.returned_at ? (l.condition === 'good' ? 'approved' : 'rejected') : l.overdue ? 'rejected' : 'pending'} label={l.returned_at ? (l.condition === 'good' ? 'Kembali' : conditions[l.condition ?? '']) : l.overdue ? 'Terlambat' : 'Dipinjam'} />
                            {l.returned_at && <div className="max-w-xs text-xs text-muted-foreground">{fmtDateTime(l.returned_at)} · {l.receiver ?? '—'}{l.return_note && ` · ${l.return_note}`}</div>}
                        </TableCell>
                        <TableCell>{!l.returned_at && <ReturnForm id={l.id} />}</TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <Pager page={loans} />
        </>
    );
}

export default function Inventory({ tab, filters, canManage, overdue, items, available, loans }: Props) {
    return (
        <AppLayout title="Inventaris">
            <Head title="Inventaris" />
            {overdue > 0 && <Alert variant="destructive"><AlertDescription>{overdue} peminjaman terlambat. <Link href="/inventaris?tab=pinjaman&status=terlambat" className="underline">Lihat</Link></AlertDescription></Alert>}
            <PageTabs tabs={{ barang: 'Barang', pinjaman: 'Peminjaman' }} current={tab} />
            {tab === 'barang' && items && <Items items={items} canManage={canManage} filters={filters} />}
            {tab === 'pinjaman' && loans && <Loans loans={loans} available={available ?? []} filters={filters} />}
        </AppLayout>
    );
}
