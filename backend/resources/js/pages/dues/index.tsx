import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { DataTable } from '@/components/data-table';
import { FormField } from '@/components/form-field';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { TableCell, TableRow } from '@/components/ui/table';
import FinanceLayout from '@/layouts/finance-layout';
import { fmtDate } from '@/lib/format';
import type { Paginated } from '@/types';

type Summary = { unpaid: number; paid: number; waived: number; paid_total: number; unpaid_total: number };
type Row = { id: number; title: string; amount: number; due_date: string | null; cancelled_at: string | null; term: string | null; summary: Summary };

const rupiah = (n: number) => `Rp${n.toLocaleString('id-ID')}`;

export default function Dues({ dues, term, canManage }: { dues: Paginated<Row>; term: string | null; canManage: boolean }) {
    const form = useForm({ title: '', amount: '', due_on: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/iuran'); };
    return (
        <FinanceLayout title="Iuran" actions={<Button variant="outline" render={<Link href="/kas" />}>Kas</Button>}>
            <Head title="Iuran" />
            {canManage ? (
                <Card className="max-w-4xl">
                    <CardHeader>
                        <CardTitle>Buat tagihan{term ? ` · ${term}` : ''}</CardTitle>
                        <CardDescription>Nominal sama untuk semua anggota aktif. Nominal tidak bisa diubah setelah dibuat; bila salah, batalkan dan buat lagi.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-4 sm:items-end">
                            <FormField label="Judul" error={form.errors.title}><Input required maxLength={100} placeholder="Iuran November 2026" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} /></FormField>
                            <FormField label="Nominal (Rp)" error={form.errors.amount}><Input required type="number" min={1} max={10000000} value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} /></FormField>
                            <FormField label="Jatuh tempo (opsional)" error={form.errors.due_on}><Input type="date" value={form.data.due_on} onChange={(e) => form.setData('due_on', e.target.value)} /></FormField>
                            <Button type="submit" disabled={form.processing}>Buat Tagihan</Button>
                        </form>
                    </CardContent>
                </Card>
            ) : <p className="text-sm text-muted-foreground">Akses audit: Anda bisa melihat dan mengunduh, tidak bisa mengubah.</p>}
            <DataTable head={['Tagihan', 'Nominal', 'Lunas', 'Belum lunas', 'Dibebaskan', 'Status']} count={dues.data.length} empty="Belum ada tagihan.">
                {dues.data.map((d) => (
                    <TableRow key={d.id}>
                        <TableCell><Link href={`/iuran/${d.id}`} className="font-medium hover:underline">{d.title}</Link><div className="text-xs text-muted-foreground">{[d.term, d.due_date && `jatuh tempo ${fmtDate(d.due_date)}`].filter(Boolean).join(' · ')}</div></TableCell>
                        <TableCell className="tabular-nums">{rupiah(d.amount)}</TableCell>
                        <TableCell className="tabular-nums">{d.summary.paid}<div className="text-xs text-muted-foreground">{rupiah(d.summary.paid_total)}</div></TableCell>
                        <TableCell className="tabular-nums">{d.summary.unpaid}<div className="text-xs text-muted-foreground">{rupiah(d.summary.unpaid_total)}</div></TableCell>
                        <TableCell className="tabular-nums">{d.summary.waived}</TableCell>
                        <TableCell><StatusBadge status={d.cancelled_at ? 'void' : 'open'} label={d.cancelled_at ? 'Dibatalkan' : 'Berjalan'} /></TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <Pager page={dues} />
        </FinanceLayout>
    );
}
