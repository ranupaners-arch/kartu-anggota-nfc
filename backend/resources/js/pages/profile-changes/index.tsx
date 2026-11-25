import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, UserRound } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/data-table';
import { FormField } from '@/components/form-field';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { TableCell, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { fmtDateTime } from '@/lib/format';
import type { Paginated } from '@/types';

type Change = {
    id: number; name: string | null; phone: string | null; reason: string | null; status: 'pending' | 'approved' | 'rejected';
    review_note: string | null; created_at: string; reviewed_at: string | null; photo_url: string | null; reviewer: string | null;
    member: { id: number; member_no: string; name: string; phone: string | null; photo_url: string | null } | null;
};

function Photo({ url, label }: { url: string | null; label: string }) {
    return url
        ? <img src={url} alt={label} className="size-20 rounded-lg border object-cover" />
        : <div className="flex size-20 items-center justify-center rounded-lg border bg-muted" aria-label={`${label}: tidak ada`}><UserRound className="size-8 text-muted-foreground" /></div>;
}

/** Ringkasan perubahan: lama → baru untuk tiap isian yang diajukan. */
function Diff({ c, withPhoto }: { c: Change; withPhoto?: boolean }) {
    return (
        <div className="grid gap-1.5">
            {c.name && <div><span className="text-muted-foreground">Nama:</span> {c.member?.name ?? '-'} <ArrowRight className="inline size-3.5" /> <b>{c.name}</b></div>}
            {c.phone && <div><span className="text-muted-foreground">No. HP:</span> {c.member?.phone ?? '-'} <ArrowRight className="inline size-3.5" /> <b>{c.phone}</b></div>}
            {c.photo_url && (withPhoto ? (
                <div className="flex items-center gap-2">
                    <Photo url={c.member?.photo_url ?? null} label="Foto lama" /><ArrowRight className="size-4" /><Photo url={c.photo_url} label="Foto baru" />
                </div>
            ) : <div><span className="text-muted-foreground">Foto:</span> <b>foto baru</b></div>)}
        </div>
    );
}

function ReviewDialog({ c }: { c: Change }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ action: 'approve', note: '' });
    const submit = (action: 'approve' | 'reject') => {
        form.transform((d) => ({ ...d, action }));
        form.post(`/perubahan-data/${c.id}`, { preserveScroll: true, onSuccess: () => setOpen(false) });
    };
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={<Button size="sm" />}>Tinjau</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Perubahan data · {c.member?.name}</DialogTitle>
                    <DialogDescription>{c.member?.member_no}{c.reason ? `: “${c.reason}”` : ''}</DialogDescription>
                </DialogHeader>
                <div className="grid gap-4 text-sm">
                    <Diff c={c} withPhoto />
                    <FormField label="Catatan untuk anggota" hint="Wajib diisi bila ditolak, agar anggota tahu apa yang perlu diperbaiki." error={form.errors.note}>
                        <Textarea rows={2} maxLength={500} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                    </FormField>
                </div>
                <DialogFooter>
                    <Button variant="destructive" disabled={form.processing} onClick={() => submit('reject')}>Tolak</Button>
                    <Button disabled={form.processing} onClick={() => submit('approve')}>Setujui & terapkan</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function ProfileChanges({ items }: { items: Paginated<Change> }) {
    return (
        <AppLayout title="Perubahan Data">
            <Head title="Perubahan Data" />
            <p className="text-sm text-muted-foreground">
                Anggota mengajukan perubahan nama, no. HP, atau foto dari app/portal. Data baru baru berlaku setelah Anda <b>setujui</b>;
                bila ditolak, data lama tetap dipakai dan anggota menerima catatan Anda.
            </p>
            <DataTable head={['Diajukan', 'Anggota', 'Perubahan', 'Alasan', 'Status', 'Tindakan']} count={items.data.length} empty="Belum ada pengajuan perubahan data.">
                {items.data.map((c) => (
                    <TableRow key={c.id}>
                        <TableCell>{fmtDateTime(c.created_at)}</TableCell>
                        <TableCell>
                            {c.member ? <Link href={`/anggota/${c.member.id}`} className="font-medium hover:underline">{c.member.name}</Link> : '-'}
                            <div className="text-xs text-muted-foreground">{c.member?.member_no}</div>
                        </TableCell>
                        <TableCell className="whitespace-normal"><Diff c={c} /></TableCell>
                        <TableCell className="max-w-xs whitespace-normal">{c.reason ?? '-'}</TableCell>
                        <TableCell><StatusBadge status={c.status} />{c.reviewer && <div className="text-xs text-muted-foreground">{c.reviewer}</div>}</TableCell>
                        <TableCell>
                            {c.status === 'pending' ? <ReviewDialog c={c} /> : (
                                <span className="text-xs text-muted-foreground">
                                    {fmtDateTime(c.reviewed_at)}
                                    {c.review_note && <span className="block">{c.review_note}</span>}
                                </span>
                            )}
                        </TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <Pager page={items} />
        </AppLayout>
    );
}
