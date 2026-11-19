import { Head, router, useForm } from '@inertiajs/react';
import { Paperclip, Plus } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { DataTable } from '@/components/data-table';
import { FormField } from '@/components/form-field';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { PageTabs } from '@/components/page-tabs';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDate } from '@/lib/format';
import type { Paginated } from '@/types';

type Excuse = {
    id: number; type: string; reason: string; status: string; review_note: string | null; attachment_path: string | null;
    member: { member_no: string; name: string } | null; event: { title: string; starts_at: string } | null;
};

type Ev = { id: number; title: string; starts_at: string; closed_at: string | null };

/** Pengurus mencatat izin atas nama anggota (mis. yang tidak memakai app). */
function RecordExcuse({ events }: { events: Ev[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ event_id: string; member_no: string; type: string; reason: string; attachment: File | null; approve: boolean }>({
        event_id: events[0] ? String(events[0].id) : '', member_no: '', type: 'izin', reason: '', attachment: null, approve: true,
    });
    const selected = events.find((e) => String(e.id) === form.data.event_id);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, approve: d.approve ? '1' : '' }));
        form.post('/izin', { forceFormData: true, preserveScroll: true, onSuccess: () => { setOpen(false); form.reset(); } });
    };
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={<Button />}><Plus /> Catat Izin</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Catat izin anggota</DialogTitle>
                        <DialogDescription>Untuk anggota yang mengabarkan izin/sakit langsung ke pengurus.</DialogDescription>
                    </DialogHeader>
                    <FormField label="Kegiatan" error={form.errors.event_id}>
                        <NativeSelect className="w-full" required value={form.data.event_id} onChange={(e) => form.setData('event_id', e.target.value)}>
                            {events.map((e) => <NativeSelectOption key={e.id} value={e.id}>{e.title} · {fmtDate(e.starts_at)}{e.closed_at ? ' (ditutup)' : ''}</NativeSelectOption>)}
                        </NativeSelect>
                    </FormField>
                    {selected?.closed_at && <p className="text-sm text-muted-foreground">Kegiatan sudah ditutup: bila disetujui, status Alpa anggota ini otomatis diganti Izin/Sakit.</p>}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField label="Nomor anggota" error={form.errors.member_no}>
                            <Input required value={form.data.member_no} onChange={(e) => form.setData('member_no', e.target.value)} />
                        </FormField>
                        <FormField label="Jenis" error={form.errors.type}>
                            <NativeSelect className="w-full" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                                <NativeSelectOption value="izin">Izin</NativeSelectOption>
                                <NativeSelectOption value="sakit">Sakit</NativeSelectOption>
                            </NativeSelect>
                        </FormField>
                    </div>
                    <FormField label="Alasan" error={form.errors.reason}>
                        <Textarea rows={2} required value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </FormField>
                    <FormField label="Bukti (opsional)" hint="Gambar JPG/PNG atau PDF, maks. 5 MB." error={form.errors.attachment}>
                        <Input type="file" accept=".jpg,.jpeg,.png,.pdf" onChange={(e) => form.setData('attachment', e.target.files?.[0] ?? null)} />
                    </FormField>
                    <Label className="flex items-center gap-2 font-normal">
                        <Checkbox checked={form.data.approve} onCheckedChange={(v) => form.setData('approve', v === true)} /> Langsung setujui
                    </Label>
                    <DialogFooter><Button type="submit" disabled={form.processing || !form.data.event_id}>Simpan</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Review({ excuse }: { excuse: Excuse }) {
    const [note, setNote] = useState('');
    const send = (status: 'approved' | 'rejected') => router.patch(`/izin/${excuse.id}`, { status, note }, { preserveScroll: true });
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <Input className="h-7 w-36" placeholder="Catatan (opsional)" value={note} onChange={(e) => setNote(e.target.value)} />
            <Button size="sm" onClick={() => send('approved')}>Setujui</Button>
            <Button size="sm" variant="destructive" onClick={() => send('rejected')}>Tolak</Button>
        </div>
    );
}

export default function Excuses({ tab, excuses, events }: { tab: string; excuses: Paginated<Excuse>; events: Ev[] }) {
    return (
        <AppLayout title="Pengajuan Izin" actions={<RecordExcuse events={events} />}>
            <Head title="Pengajuan Izin" />
            <PageTabs tabs={{ menunggu: 'Menunggu', riwayat: 'Riwayat' }} current={tab} />
            <DataTable head={['Anggota', 'Kegiatan', 'Jenis', 'Alasan', 'Bukti', tab === 'menunggu' ? 'Tindakan' : 'Keputusan']} count={excuses.data.length} empty="Tidak ada pengajuan.">
                {excuses.data.map((x) => (
                    <TableRow key={x.id}>
                        <TableCell>{x.member?.name}<div className="text-xs text-muted-foreground">{x.member?.member_no}</div></TableCell>
                        <TableCell>{x.event?.title}<div className="text-xs text-muted-foreground">{fmtDate(x.event?.starts_at)}</div></TableCell>
                        <TableCell><StatusBadge status={x.type} /></TableCell>
                        <TableCell className="max-w-xs whitespace-normal">{x.reason}</TableCell>
                        <TableCell>{x.attachment_path
                            ? <a href={`/izin/${x.id}/bukti`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-primary hover:underline"><Paperclip className="size-3.5" /> Lihat</a>
                            : <span className="text-muted-foreground">—</span>}</TableCell>
                        <TableCell>{x.status === 'pending' ? <Review excuse={x} /> : (
                            <div><StatusBadge status={x.status} />{x.review_note && <div className="mt-1 text-xs text-muted-foreground">{x.review_note}</div>}</div>
                        )}</TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <Pager page={excuses} />
        </AppLayout>
    );
}
