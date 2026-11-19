import { Head, Link, router, useForm } from '@inertiajs/react';
import { FileSpreadsheet, FileText, MonitorPlay, Pencil } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/data-table';
import { FormField } from '@/components/form-field';
import { PageTabs } from '@/components/page-tabs';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { TableCell, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { fmtDateTime, fmtTime, toLocalInput } from '@/lib/format';

type Ev = {
    id: number; title: string; location: string | null; starts_at: string; ends_at: string; checkin_opens_at: string;
    late_after: string; checkin_closes_at: string; closed_at: string | null;
};
type Row = { member_id: number; attendance_id: number | null; member_no: string; name: string; division: string | null; status: string; scanned_at: string | null; source: string | null; needs_review: boolean };
type Report = { session: { id: number; title: string }; summary: { total: number; by_status: Record<string, number>; present_rate: number | null }; rows: Row[] } | null;
type Session = { id: number; title: string; starts_at: string | null; ends_at: string | null; checkin_opens_at: string; late_after: string; checkin_closes_at: string };

interface Props {
    event: Ev; tab: string; report: Report; canDelete: boolean;
    sessions: Session[];
    divisions: { id: number; name: string }[]; targetDivisionIds: number[]; targetMemberNos: string[];
}

const statusLabels: Record<string, string> = { hadir: 'Hadir', telat: 'Telat', izin: 'Izin', sakit: 'Sakit', alpa: 'Alpa', belum_absen: 'Belum absen' };

function CorrectionForm({ row }: { row: Row }) {
    const [status, setStatus] = useState(row.status);
    const [reason, setReason] = useState('');
    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.patch(`/absensi/${row.attendance_id}`, { status, reason }, { preserveScroll: true, onSuccess: () => setReason('') });
    };
    return (
        <form onSubmit={submit} className="flex items-center gap-1.5">
            <NativeSelect size="sm" value={status} onChange={(e) => setStatus(e.target.value)}>
                {['hadir', 'telat', 'izin', 'sakit', 'alpa'].map((s) => <NativeSelectOption key={s} value={s}>{statusLabels[s]}</NativeSelectOption>)}
                <NativeSelectOption value="void">Batalkan</NativeSelectOption>
            </NativeSelect>
            <Input className="h-7 w-32" placeholder="Alasan" required value={reason} onChange={(e) => setReason(e.target.value)} />
            <Button size="sm" variant="outline" type="submit">Simpan</Button>
        </form>
    );
}

/** Kegiatan multi-sesi: pilih sesi untuk ringkasan & daftar hadir (tiap sesi = satu kehadiran). */
function SessionPicker({ event, tab, sessions, current }: { event: Ev; tab: string; sessions: Session[]; current: number }) {
    if (sessions.length < 2) return null;
    return (
        <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm text-muted-foreground">Sesi:</span>
            {sessions.map((s) => (
                <Button key={s.id} size="sm" variant={s.id === current ? 'default' : 'outline'}
                    render={<Link href={`/kegiatan/${event.id}?tab=${tab}&sesi=${s.id}`} preserveScroll />}>{s.title}</Button>
            ))}
        </div>
    );
}

function Summary({ event, report, canDelete }: Pick<Props, 'event' | 'report' | 'canDelete'>) {
    if (!report) return null;
    const exportReport = (format: 'xlsx' | 'pdf') => router.post('/ekspor', { report: 'event', event_id: event.id, format });
    return (
        <>
            <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-8">
                <Card size="sm"><CardHeader><CardDescription>Peserta</CardDescription><CardTitle className="text-2xl tabular-nums">{report.summary.total}</CardTitle></CardHeader></Card>
                {Object.entries(report.summary.by_status).map(([s, n]) => (
                    <Card size="sm" key={s}><CardHeader><CardDescription>{statusLabels[s] ?? s}</CardDescription><CardTitle className="text-2xl tabular-nums">{n}</CardTitle></CardHeader></Card>
                ))}
                <Card size="sm"><CardHeader><CardDescription>Kehadiran</CardDescription><CardTitle className="text-2xl tabular-nums">{report.summary.present_rate ?? '–'}%</CardTitle></CardHeader></Card>
            </div>
            <Card>
                <CardHeader><CardTitle>Ekspor rekap kegiatan</CardTitle><CardDescription>Hasilnya muncul di Rekap &amp; Ekspor → Hasil Ekspor.</CardDescription></CardHeader>
                <CardContent className="flex flex-wrap gap-2">
                    <Button variant="outline" onClick={() => exportReport('xlsx')}><FileSpreadsheet /> Excel</Button>
                    <Button variant="outline" onClick={() => exportReport('pdf')}><FileText /> PDF</Button>
                    {canDelete && (
                        <ConfirmAction destructive title="Hapus kegiatan?" description="Kegiatan yang belum punya absensi akan dihapus permanen." href={`/kegiatan/${event.id}`} method="delete" confirmLabel="Hapus">
                            Hapus Kegiatan
                        </ConfirmAction>
                    )}
                </CardContent>
            </Card>
        </>
    );
}

/** Ubah sesi. Sesi utama: judul saja (jadwalnya mengikuti kegiatan). */
function EditSession({ event, session, isDefault }: { event: Ev; session: Session; isDefault: boolean }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ title: session.title, starts_at: toLocalInput(session.starts_at), ends_at: toLocalInput(session.ends_at), reason: '' });
    const opened = new Date(session.checkin_opens_at).getTime() <= Date.now();
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/kegiatan/${event.id}/sesi/${session.id}`, { preserveScroll: true, onSuccess: () => setOpen(false) });
    };
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={<Button size="sm" variant="outline" />}><Pencil /> Ubah</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Ubah sesi</DialogTitle>
                        <DialogDescription>{isDefault ? 'Jadwal sesi utama mengikuti jadwal kegiatan (ubah lewat tombol Ubah kegiatan).' : 'Jendela check-in dihitung ulang dari jam mulai baru.'}</DialogDescription>
                    </DialogHeader>
                    <FormField label="Judul sesi" error={form.errors.title}><Input required value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} /></FormField>
                    {!isDefault && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField label="Mulai" error={form.errors.starts_at}><Input type="datetime-local" required value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} /></FormField>
                            <FormField label="Selesai" error={form.errors.ends_at}><Input type="datetime-local" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} /></FormField>
                        </div>
                    )}
                    {!isDefault && opened && (
                        <FormField label="Alasan perubahan jam" hint="Wajib bila jam diubah, karena check-in sesi ini sudah dibuka." error={form.errors.reason}>
                            <Input value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                        </FormField>
                    )}
                    <DialogFooter><Button type="submit" disabled={form.processing}>Simpan</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Sessions({ event, sessions }: Pick<Props, 'event' | 'sessions'>) {
    const form = useForm({ title: '', starts_at: '', ends_at: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post(`/kegiatan/${event.id}/sesi`, { preserveScroll: true, onSuccess: () => form.reset() }); };
    return (
        <>
            <DataTable head={['Sesi', 'Mulai', 'Selesai', 'Check-in', 'Telat setelah', '']} count={sessions.length} empty="Belum ada sesi.">
                {sessions.map((s, i) => (
                    <TableRow key={s.id}>
                        <TableCell>{s.title} {i === 0 && <StatusBadge status="open" label="Utama" />}</TableCell>
                        <TableCell>{fmtDateTime(s.starts_at)}</TableCell><TableCell>{fmtDateTime(s.ends_at)}</TableCell>
                        <TableCell className="tabular-nums">{fmtDateTime(s.checkin_opens_at)}–{fmtTime(s.checkin_closes_at)}</TableCell>
                        <TableCell className="tabular-nums">{fmtTime(s.late_after)}</TableCell>
                        <TableCell className="text-right">
                            {!event.closed_at && (
                                <div className="flex justify-end gap-1.5">
                                    <EditSession event={event} session={s} isDefault={i === 0} />
                                    {i > 0 && (
                                        <ConfirmAction size="sm" destructive title={`Hapus sesi ${s.title}?`} description="Hanya sesi tanpa absensi yang bisa dihapus." href={`/kegiatan/${event.id}/sesi/${s.id}`} method="delete" confirmLabel="Hapus">Hapus</ConfirmAction>
                                    )}
                                </div>
                            )}
                        </TableCell>
                    </TableRow>
                ))}
            </DataTable>
            {!event.closed_at && <Card>
                <CardHeader>
                    <CardTitle>Tambah sesi</CardTitle>
                    <CardDescription>
                        Tiap sesi dihitung sebagai satu kehadiran di rekap, dan Alpa ditandai per sesi saat kegiatan ditutup.
                        Jendela check-in sesi mengikuti jam mulainya dengan selisih yang sama seperti kegiatan.
                        Di app, petugas memilih sesi (otomatis sesi yang sedang buka).
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-4 sm:items-end">
                        <FormField label="Judul sesi" error={form.errors.title}><Input required value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} /></FormField>
                        <FormField label="Mulai" error={form.errors.starts_at}><Input type="datetime-local" required value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} /></FormField>
                        <FormField label="Selesai" error={form.errors.ends_at}><Input type="datetime-local" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} /></FormField>
                        <Button type="submit" disabled={form.processing}>Tambah Sesi</Button>
                    </form>
                </CardContent>
            </Card>}
        </>
    );
}

function Targets({ event, divisions, targetDivisionIds, targetMemberNos }: Pick<Props, 'event' | 'divisions' | 'targetDivisionIds' | 'targetMemberNos'>) {
    const form = useForm({ division_ids: targetDivisionIds, member_nos: targetMemberNos.join(', ') });
    const submit = (e: FormEvent) => { e.preventDefault(); form.put(`/kegiatan/${event.id}/target`, { preserveScroll: true }); };
    return (
        <Card className="max-w-3xl">
            <CardHeader><CardTitle>Target peserta</CardTitle><CardDescription>Kosongkan semua untuk menargetkan seluruh anggota aktif periode ini.</CardDescription></CardHeader>
            <CardContent>
                <form onSubmit={submit} className="grid gap-4">
                    <FormField label="Divisi">
                        <div className="flex flex-wrap gap-4">
                            {divisions.map((d) => (
                                <Label key={d.id} className="flex items-center gap-2 font-normal">
                                    <Checkbox checked={form.data.division_ids.includes(d.id)}
                                        onCheckedChange={(v) => form.setData('division_ids', v === true ? [...form.data.division_ids, d.id] : form.data.division_ids.filter((x) => x !== d.id))} />
                                    {d.name}
                                </Label>
                            ))}
                        </div>
                    </FormField>
                    <FormField label="Anggota tambahan" hint="Nomor anggota, pisahkan dengan koma atau baris baru." error={form.errors.member_nos}>
                        <Textarea rows={3} value={form.data.member_nos} onChange={(e) => form.setData('member_nos', e.target.value)} />
                    </FormField>
                    <div><Button type="submit" disabled={form.processing}>Simpan Target</Button></div>
                </form>
            </CardContent>
        </Card>
    );
}

export default function EventShow(props: Props) {
    const { event, tab, report } = props;
    return (
        <AppLayout title={event.title} actions={
            <>
                <Button variant="outline" render={<a href={`/kegiatan/${event.id}/monitor`} target="_blank" rel="noreferrer" />}><MonitorPlay /> Monitor langsung</Button>
                <Button variant="outline" render={<Link href={`/kegiatan/${event.id}/edit`} />}><Pencil /> Ubah</Button>
                {!event.closed_at && (
                    <ConfirmAction title="Tutup kegiatan?" description={props.sessions.length > 1
                        ? 'Per sesi yang sudah dibuka, anggota wajib yang tidak scan ditandai Alpa, atau Izin/Sakit bila izinnya disetujui. Sesi yang belum dibuka tidak ditandai.'
                        : 'Anggota wajib yang tidak scan akan ditandai Alpa, atau Izin/Sakit bila izinnya disetujui.'} href={`/kegiatan/${event.id}/tutup`} variant="default" confirmLabel="Tutup kegiatan">
                        Tutup Kegiatan
                    </ConfirmAction>
                )}
            </>
        }>
            <Head title={event.title} />
            <p className="text-sm text-muted-foreground">
                {fmtDateTime(event.starts_at)}–{fmtTime(event.ends_at)} · {event.location ?? 'tanpa lokasi'} · check-in {fmtTime(event.checkin_opens_at)}–{fmtTime(event.checkin_closes_at)}, telat setelah {fmtTime(event.late_after)} ·{' '}
                <StatusBadge status={event.closed_at ? 'closed' : 'open'} />
            </p>
            <PageTabs tabs={{ ringkasan: 'Ringkasan', hadir: 'Daftar Hadir', sesi: 'Sesi', target: 'Target Peserta' }} current={tab} />
            {(tab === 'ringkasan' || tab === 'hadir') && report && <SessionPicker event={event} tab={tab} sessions={props.sessions} current={report.session.id} />}
            {tab === 'ringkasan' && <Summary {...props} />}
            {tab === 'hadir' && report && (
                <DataTable head={['No. Anggota', 'Nama', 'Divisi', 'Status', 'Waktu', 'Koreksi']} count={report.rows.length} empty="Belum ada peserta.">
                    {report.rows.map((r) => (
                        <TableRow key={r.member_id}>
                            <TableCell className="tabular-nums">{r.member_no}</TableCell><TableCell>{r.name}</TableCell><TableCell>{r.division ?? '—'}</TableCell>
                            <TableCell className="space-x-1"><StatusBadge status={r.status} />{r.needs_review && <StatusBadge status="pending" label="Perlu ditinjau" />}</TableCell>
                            <TableCell>{r.scanned_at ? fmtTime(r.scanned_at) : '—'} <span className="text-xs text-muted-foreground">{r.source}</span></TableCell>
                            <TableCell>{r.attendance_id ? <CorrectionForm row={r} /> : <span className="text-muted-foreground">—</span>}</TableCell>
                        </TableRow>
                    ))}
                </DataTable>
            )}
            {tab === 'sesi' && <Sessions {...props} />}
            {tab === 'target' && <Targets {...props} />}
        </AppLayout>
    );
}
