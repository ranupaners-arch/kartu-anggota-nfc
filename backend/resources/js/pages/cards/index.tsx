import { Head, Link, router, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { type CardRow, CardsTable } from '@/components/cards-table';
import { FormField } from '@/components/form-field';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { DataTable } from '@/components/data-table';
import { PageTabs } from '@/components/page-tabs';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDateTime } from '@/lib/format';
import type { Paginated } from '@/types';

type Rejected = { id: number; created_at: string; card_status: string | null; event_id: number | null; device_id: number | null; member: { member_no: string; name: string } | null };

const reasonLabel: Record<string, string> = { hilang: 'Hilang', rusak: 'Rusak', tanpa_nfc: 'HP tanpa NFC' };

type Req = {
    id: number; reason: 'hilang' | 'rusak' | 'tanpa_nfc'; note: string | null; status: 'pending' | 'approved' | 'rejected'; resolution: 'self' | 'admin' | null;
    created_at: string; reviewed_at: string | null; review_note: string | null;
    member: { id: number; member_no: string; name: string; self_card_writes_left: number } | null; reviewer: { name: string } | null;
};

/** Putuskan laporan kartu: izinkan anggota mendaftarkan sendiri, pengurus terbitkan, atau tolak — dengan catatan. */
function ReviewDialog({ req }: { req: Req }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ action: 'approve', resolution: req.reason === 'tanpa_nfc' ? 'admin' : 'self', note: '' });
    const submit = (action: 'approve' | 'reject') => {
        form.transform((d) => ({ ...d, action }));
        form.post(`/kartu/permintaan/${req.id}`, { preserveScroll: true, onSuccess: () => setOpen(false) });
    };
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={<Button size="sm" />}>Tindak lanjuti</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Laporan kartu · {req.member?.name}</DialogTitle>
                    <DialogDescription>{reasonLabel[req.reason]}{req.note ? `: “${req.note}”` : ''}</DialogDescription>
                </DialogHeader>
                <div className="grid gap-3 text-sm">
                    <Label className="flex items-start gap-2 font-normal">
                        <input type="radio" name="res" className="mt-1" checked={form.data.resolution === 'self'} onChange={() => form.setData('resolution', 'self')} />
                        <span><b>Izinkan daftar sendiri</b>: anggota bisa mendaftarkan 1 kartu NFC baru lewat app.</span>
                    </Label>
                    <Label className="flex items-start gap-2 font-normal">
                        <input type="radio" name="res" className="mt-1" checked={form.data.resolution === 'admin'} onChange={() => form.setData('resolution', 'admin')} />
                        <span><b>Pengurus terbitkan</b>: tulis kartu lewat app petugas, lalu serahkan.</span>
                    </Label>
                    <FormField label="Catatan untuk anggota (opsional)" hint="Mis. “Ambil kartu di sekretariat, Senin 13.00”." error={form.errors.note}>
                        <Textarea rows={2} maxLength={500} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                    </FormField>
                </div>
                <DialogFooter>
                    <Button variant="destructive" disabled={form.processing} onClick={() => submit('reject')}>Tolak</Button>
                    <Button disabled={form.processing} onClick={() => submit('approve')}>Setujui</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Permintaan kartu pengganti dari anggota: setujui = anggota dapat 1 jatah daftar kartu sendiri lagi. */
function Requests({ items }: { items: Req[] }) {
    return (
        <>
            <p className="text-sm text-muted-foreground">
                Anggota hanya bisa mendaftarkan kartu NFC sendiri <b>satu kali</b> lewat app. Kartu hilang/rusak setelahnya diajukan di sini;
                <b> Setujui</b> memberi satu kesempatan lagi (kartu lama sudah/akan diblokir otomatis). Laporan <i>hilang</i> langsung memblokir kartu aktifnya.
            </p>
            <DataTable head={['Diajukan', 'Anggota', 'Alasan', 'Catatan', 'Status', 'Tindakan']} count={items.length} empty="Belum ada permintaan.">
                {items.map((r) => (
                    <TableRow key={r.id}>
                        <TableCell>{fmtDateTime(r.created_at)}</TableCell>
                        <TableCell>{r.member ? <Link href={`/anggota/${r.member.id}?tab=kartu`} className="font-medium hover:underline">{r.member.name}</Link> : '-'}<div className="text-xs text-muted-foreground">{r.member?.member_no}</div></TableCell>
                        <TableCell>{reasonLabel[r.reason] ?? r.reason}</TableCell>
                        <TableCell className="max-w-xs whitespace-normal">{r.note ?? '-'}</TableCell>
                        <TableCell><StatusBadge status={r.status} />{r.reviewer && <div className="text-xs text-muted-foreground">{r.reviewer.name}</div>}</TableCell>
                        <TableCell>
                            {r.status === 'pending' ? <ReviewDialog req={r} /> : (
                                <span className="text-xs text-muted-foreground">
                                    {r.resolution === 'admin' ? 'Diterbitkan pengurus' : r.resolution === 'self' ? 'Daftar sendiri' : ''} · {fmtDateTime(r.reviewed_at)}
                                    {r.review_note && <span className="block">{r.review_note}</span>}
                                </span>
                            )}
                        </TableCell>
                    </TableRow>
                ))}
            </DataTable>
        </>
    );
}

export default function Cards({ tab, items, filters }: { tab: string; items: Paginated<CardRow | Rejected | Req>; filters: { q: string | null; status: string | null } }) {
    const [q, setQ] = useState(filters.q ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const apply = (e: FormEvent) => { e.preventDefault(); router.get('/kartu', { tab, q: q || undefined, status: status || undefined }, { preserveState: true }); };

    return (
        <AppLayout title="Kartu">
            <Head title="Kartu" />
            <PageTabs tabs={{ semua: 'Semua Kartu', pengganti: 'Permintaan Pengganti', ditolak: 'Percobaan Ditolak' }} current={tab} />
            {tab === 'pengganti' ? <Requests items={items.data as Req[]} /> : tab === 'ditolak' ? (
                <>
                    <p className="text-sm text-muted-foreground">Kartu diblokir/hilang yang dicoba dipakai absen.</p>
                    <DataTable head={['Waktu', 'Anggota', 'Status kartu', 'Kegiatan', 'Perangkat']} count={items.data.length} empty="Belum ada percobaan.">
                        {(items.data as Rejected[]).map((r) => (
                            <TableRow key={r.id}>
                                <TableCell>{fmtDateTime(r.created_at)}</TableCell>
                                <TableCell>{r.member ? `${r.member.name} (${r.member.member_no})` : '-'}</TableCell>
                                <TableCell>{r.card_status ? <StatusBadge status={r.card_status} /> : '-'}</TableCell>
                                <TableCell>#{r.event_id ?? '?'}</TableCell><TableCell>#{r.device_id ?? '?'}</TableCell>
                            </TableRow>
                        ))}
                    </DataTable>
                </>
            ) : (
                <>
                    <form onSubmit={apply} className="flex flex-wrap items-center gap-2">
                        <Input className="w-56" placeholder="Cari anggota" value={q} onChange={(e) => setQ(e.target.value)} />
                        <NativeSelect value={status} onChange={(e) => setStatus(e.target.value)}>
                            <NativeSelectOption value="">Semua status</NativeSelectOption>
                            <NativeSelectOption value="active">Aktif</NativeSelectOption>
                            <NativeSelectOption value="blocked">Diblokir</NativeSelectOption>
                            <NativeSelectOption value="lost">Hilang</NativeSelectOption>
                            <NativeSelectOption value="unassigned">Belum dipakai</NativeSelectOption>
                        </NativeSelect>
                        <Button type="submit" variant="secondary">Terapkan</Button>
                    </form>
                    <CardsTable cards={items.data as CardRow[]} />
                </>
            )}
            <Pager page={items} />
        </AppLayout>
    );
}
