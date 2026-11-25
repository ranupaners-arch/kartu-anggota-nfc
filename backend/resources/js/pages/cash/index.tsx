import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download, Paperclip } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
import { FormField } from '@/components/form-field';
import { PageTabs } from '@/components/page-tabs';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { TableCell, TableRow } from '@/components/ui/table';
import FinanceLayout from '@/layouts/finance-layout';
import { fmtDate, toLocalInput } from '@/lib/format';
import type { Paginated } from '@/types';

type Kind = 'in' | 'out';
type Category = { id: number; kind: Kind; name: string; is_dues: boolean; entries_count: number };
type Entry = {
    id: number; kind: Kind; amount: number; date: string; description: string; member: string | null; is_due: boolean; category: string | null; has_receipt: boolean;
    by: string | null; voided_at: string | null; voided_by: string | null; void_reason: string | null;
};
type Report = { opening: number; in: number; out: number; closing: number; categories: { kind: Kind; name: string; total: number; count: number }[] };
type Filters = { q: string | null; kind: string | null; category: string | null; from: string | null; to: string | null };
type Props = { tab: string; balance: number; canManage: boolean; filters: Filters; categories: Category[]; entries: Paginated<Entry> | null; report: Report | null };

const rupiah = (n: number) => `${n < 0 ? '-' : ''}Rp${Math.abs(n).toLocaleString('id-ID')}`;
const kinds: Record<Kind, string> = { in: 'Uang masuk', out: 'Uang keluar' };

function NewEntry({ categories }: { categories: Category[] }) {
    const today = toLocalInput(new Date().toISOString()).slice(0, 10);
    const form = useForm<{ kind: Kind; category_id: string; amount: string; occurred_on: string; description: string; receipt: File | null }>({ kind: 'out', category_id: '', amount: '', occurred_on: today, description: '', receipt: null });
    const [fileKey, setFileKey] = useState(0);
    const options = categories.filter((c) => c.kind === form.data.kind && !c.is_dues);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/kas', { preserveScroll: true, forceFormData: true, onSuccess: () => { form.reset('amount', 'description', 'receipt'); setFileKey((k) => k + 1); } });
    };
    return (
        <Card className="max-w-5xl">
            <CardHeader><CardTitle>Catat transaksi</CardTitle><CardDescription>Transaksi tidak bisa diubah. Bila salah, batalkan dengan alasan lalu catat lagi. Iuran masuk otomatis saat ditandai lunas.</CardDescription></CardHeader>
            <CardContent>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid items-start gap-4 sm:grid-cols-4">
                        <FormField label="Jenis" error={form.errors.kind}>
                            <NativeSelect className="w-full" value={form.data.kind} onChange={(e) => form.setData({ ...form.data, kind: e.target.value as Kind, category_id: '' })}>
                                {Object.entries(kinds).map(([k, l]) => <NativeSelectOption key={k} value={k}>{l}</NativeSelectOption>)}
                            </NativeSelect>
                        </FormField>
                        <FormField label="Kategori" error={form.errors.category_id}>
                            <NativeSelect className="w-full" required value={form.data.category_id} onChange={(e) => form.setData('category_id', e.target.value)}>
                                <NativeSelectOption value="">Pilih kategori</NativeSelectOption>
                                {options.map((c) => <NativeSelectOption key={c.id} value={c.id}>{c.name}</NativeSelectOption>)}
                            </NativeSelect>
                        </FormField>
                        <FormField label="Nominal (Rp)" error={form.errors.amount}><Input type="number" inputMode="numeric" required min={1} max={10000000000} value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} /></FormField>
                        <FormField label="Tanggal" error={form.errors.occurred_on}><Input type="date" required max={today} value={form.data.occurred_on} onChange={(e) => form.setData('occurred_on', e.target.value)} /></FormField>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-[2fr_1fr_auto] sm:items-end">
                        <FormField label="Keterangan" error={form.errors.description}><Input required minLength={3} maxLength={200} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></FormField>
                        <FormField label="Bukti (opsional)" error={form.errors.receipt}><Input key={fileKey} type="file" accept="image/jpeg,image/png,application/pdf" onChange={(e) => form.setData('receipt', e.target.files?.[0] ?? null)} /></FormField>
                        <Button type="submit" disabled={form.processing}>{form.processing ? 'Menyimpan…' : 'Catat'}</Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function VoidForm({ id }: { id: number }) {
    const [reason, setReason] = useState('');
    const submit = (e: FormEvent) => { e.preventDefault(); router.post(`/kas/${id}/batal`, { reason }, { preserveScroll: true }); };
    return (
        <form onSubmit={submit} className="flex items-center gap-1.5">
            <Input className="h-7 w-28" placeholder="Alasan" aria-label="Alasan pembatalan" required minLength={3} maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} />
            <Button size="sm" variant="destructive" type="submit" disabled={reason.trim().length < 3}>Batalkan</Button>
        </form>
    );
}

function Entries({ entries, filters, categories, canManage }: { entries: Paginated<Entry>; filters: Filters; categories: Category[]; canManage: boolean }) {
    const [f, setF] = useState({ q: filters.q ?? '', kind: filters.kind ?? '', category: filters.category ?? '', from: filters.from ?? '', to: filters.to ?? '' });
    return (
        <>
            <FilterBar url="/kas" values={f} search={f.q} onSearch={(q) => setF({ ...f, q })} placeholder="Cari keterangan">
                <NativeSelect value={f.kind} aria-label="Jenis" onChange={(e) => setF({ ...f, kind: e.target.value })}><NativeSelectOption value="">Semua jenis</NativeSelectOption>{Object.entries(kinds).map(([k, l]) => <NativeSelectOption key={k} value={k}>{l}</NativeSelectOption>)}</NativeSelect>
                <NativeSelect value={f.category} aria-label="Kategori" onChange={(e) => setF({ ...f, category: e.target.value })}><NativeSelectOption value="">Semua kategori</NativeSelectOption>{categories.map((c) => <NativeSelectOption key={c.id} value={c.id}>{c.name} ({c.kind === 'in' ? 'masuk' : 'keluar'})</NativeSelectOption>)}</NativeSelect>
                <Input type="date" className="w-auto" aria-label="Dari tanggal" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
                <Input type="date" className="w-auto" aria-label="Sampai tanggal" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} />
            </FilterBar>
            <DataTable head={['Tanggal', 'Keterangan', 'Kategori', 'Nominal', 'Status', '']} count={entries.data.length} empty="Belum ada transaksi.">
                {entries.data.map((e) => (
                    <TableRow key={e.id} className={e.voided_at ? 'opacity-60' : undefined}>
                        <TableCell>{fmtDate(e.date)}</TableCell>
                        <TableCell className="max-w-xs whitespace-normal">{e.description}{e.member && ` — ${e.member}`}<div className="text-xs text-muted-foreground">{e.by ?? '—'}</div></TableCell>
                        <TableCell>{e.category}</TableCell>
                        <TableCell className="tabular-nums"><span className={e.voided_at ? 'line-through' : e.kind === 'in' ? 'text-emerald-700 dark:text-emerald-400' : undefined}>{e.kind === 'in' ? '+' : '−'}{rupiah(e.amount)}</span></TableCell>
                        <TableCell className="whitespace-normal">{e.voided_at ? <><StatusBadge status="void" label="Dibatalkan" /><div className="max-w-xs text-xs text-muted-foreground">{e.voided_by}: {e.void_reason}</div></> : <StatusBadge status="active" label="Berlaku" />}</TableCell>
                        <TableCell>
                            <div className="flex flex-wrap items-center gap-1.5">
                                {e.has_receipt && <Button size="sm" variant="outline" render={<a href={`/kas/${e.id}/bukti`} target="_blank" rel="noreferrer" aria-label={`Bukti ${e.description}`} />}><Paperclip /> Bukti</Button>}
                                {canManage && !e.voided_at && (e.is_due ? <span className="text-xs text-muted-foreground">lewat Iuran</span> : <VoidForm id={e.id} />)}
                            </div>
                        </TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <Pager page={entries} />
        </>
    );
}

function ReportView({ report, filters }: { report: Report; filters: Filters }) {
    const [f, setF] = useState({ from: filters.from ?? '', to: filters.to ?? '' });
    const submit = (e: FormEvent) => { e.preventDefault(); router.get('/kas', { tab: 'laporan', from: f.from || undefined, to: f.to || undefined }, { preserveState: true }); };
    return (
        <>
            <form onSubmit={submit} className="flex flex-wrap items-center gap-2">
                <Input type="date" className="w-auto" aria-label="Dari tanggal" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
                <Input type="date" className="w-auto" aria-label="Sampai tanggal" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} />
                <Button type="submit" variant="secondary">Tampilkan</Button>
            </form>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                {([['Saldo awal', report.opening], ['Masuk', report.in], ['Keluar', report.out], ['Saldo akhir', report.closing]] as [string, number][]).map(([l, n]) => (
                    <Card key={l}><CardHeader><CardDescription>{l}</CardDescription><CardTitle className="text-lg tabular-nums sm:text-2xl">{rupiah(n)}</CardTitle></CardHeader></Card>
                ))}
            </div>
            <DataTable head={['Jenis', 'Kategori', 'Transaksi', 'Total']} count={report.categories.length} empty="Tidak ada transaksi di rentang ini.">
                {report.categories.map((c) => <TableRow key={c.kind + c.name}><TableCell>{kinds[c.kind]}</TableCell><TableCell>{c.name}</TableCell><TableCell className="tabular-nums">{c.count}</TableCell><TableCell className="tabular-nums">{rupiah(c.total)}</TableCell></TableRow>)}
            </DataTable>
        </>
    );
}

function CategoryRow({ c, canManage }: { c: Category; canManage: boolean }) {
    const [name, setName] = useState(c.name);
    return (
        <TableRow>
            <TableCell>{kinds[c.kind]}</TableCell>
            <TableCell>{canManage ? <Input className="h-8 max-w-xs" aria-label={`Nama kategori ${c.name}`} maxLength={50} value={name} onChange={(e) => setName(e.target.value)} /> : c.name}</TableCell>
            <TableCell className="tabular-nums">{c.entries_count}</TableCell>
            <TableCell className="text-right">{canManage && (
                <div className="flex flex-wrap justify-end gap-1.5">
                    <Button size="sm" variant="outline" disabled={name === c.name || !name.trim()} onClick={() => router.put(`/kas/kategori/${c.id}`, { name }, { preserveScroll: true })}>Simpan nama</Button>
                    {!c.is_dues && c.entries_count === 0 && <ConfirmAction size="sm" destructive title={`Hapus kategori ${c.name}?`} description="Hanya kategori yang belum dipakai transaksi yang bisa dihapus." href={`/kas/kategori/${c.id}`} method="delete" confirmLabel="Hapus">Hapus</ConfirmAction>}
                </div>
            )}</TableCell>
        </TableRow>
    );
}

function Categories({ categories, canManage }: { categories: Category[]; canManage: boolean }) {
    const form = useForm<{ kind: Kind; name: string }>({ kind: 'out', name: '' });
    return (
        <>
            <DataTable head={['Jenis', 'Kategori', 'Transaksi', '']} count={categories.length}>{categories.map((c) => <CategoryRow key={`${c.id}-${c.name}`} c={c} canManage={canManage} />)}</DataTable>
            {canManage && (
                <Card className="max-w-2xl">
                    <CardHeader><CardTitle>Tambah kategori</CardTitle></CardHeader>
                    <CardContent>
                        <form onSubmit={(e) => { e.preventDefault(); form.post('/kas/kategori', { preserveScroll: true, onSuccess: () => form.reset('name') }); }} className="flex flex-wrap items-end gap-2">
                            <FormField label="Jenis" error={form.errors.kind}><NativeSelect value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value as Kind)}>{Object.entries(kinds).map(([k, l]) => <NativeSelectOption key={k} value={k}>{l}</NativeSelectOption>)}</NativeSelect></FormField>
                            <div className="min-w-40 flex-1"><FormField label="Nama kategori" error={form.errors.name}><Input required maxLength={50} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></FormField></div>
                            <Button type="submit" disabled={form.processing}>Tambah</Button>
                        </form>
                    </CardContent>
                </Card>
            )}
        </>
    );
}

export default function Cash({ tab, balance, canManage, filters, categories, entries, report }: Props) {
    const query = new URLSearchParams(Object.entries(filters).filter((e): e is [string, string] => Boolean(e[1]))).toString();
    return (
        <FinanceLayout title="Kas" actions={<><Button variant="outline" render={<Link href="/iuran" />}>Iuran</Button><Button variant="outline" render={<a href={`/kas/ekspor${query ? `?${query}` : ''}`} />}><Download /> CSV</Button></>}>
            <Head title="Kas" />
            <Card className="max-w-sm"><CardHeader><CardDescription>Saldo kas sekarang</CardDescription><CardTitle className="text-3xl tabular-nums">{rupiah(balance)}</CardTitle></CardHeader></Card>
            {!canManage && <p className="text-sm text-muted-foreground">Akses audit: Anda bisa melihat dan mengunduh, tidak bisa mengubah.</p>}
            <PageTabs tabs={{ transaksi: 'Transaksi', laporan: 'Laporan', kategori: 'Kategori' }} current={tab} />
            {tab === 'transaksi' && canManage && <NewEntry categories={categories} />}
            {tab === 'transaksi' && entries && <Entries entries={entries} filters={filters} categories={categories} canManage={canManage} />}
            {tab === 'laporan' && report && <ReportView report={report} filters={filters} />}
            {tab === 'kategori' && <Categories categories={categories} canManage={canManage} />}
        </FinanceLayout>
    );
}
