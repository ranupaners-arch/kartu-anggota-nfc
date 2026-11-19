import { Head, useForm } from '@inertiajs/react';
import { BellRing, CalendarDays, MapPin, Pin } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { FormField } from '@/components/form-field';
import { StatusBadge } from '@/components/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import PortalLayout from '@/layouts/portal-layout';
import { fmtDate, fmtDateTime, fmtTime } from '@/lib/format';

type Excuse = { id: number; type: 'izin' | 'sakit'; reason: string; status: 'pending' | 'approved' | 'rejected'; review_note: string | null };
export type MemberEvent = {
    id: number; title: string; location: string | null; starts_at: string; closed_at: string | null; is_mandatory: boolean;
    my_status: string | null; sessions: number; present_sessions: number; excuse: Excuse | null; can_request_excuse: boolean;
};
type Announcement = { id: number; title: string; body: string; pinned: boolean; created_at: string; division: string | null };

const label: Record<string, string> = { hadir: 'Hadir', telat: 'Telat', izin: 'Izin', sakit: 'Sakit', alpa: 'Alpa', pending: 'menunggu', approved: 'disetujui', rejected: 'ditolak' };

/** Label status sama dengan app (MyEventsTabStatus). */
export function statusOf(e: MemberEvent, now = Date.now()): string {
    if (e.sessions > 1 && (e.my_status || e.present_sessions > 0)) return `Hadir ${e.present_sessions}/${e.sessions} sesi`;
    if (e.my_status) return label[e.my_status] ?? e.my_status;
    if (e.excuse) return `${e.excuse.type === 'sakit' ? 'Sakit' : 'Izin'} ${label[e.excuse.status]}`;
    if (e.closed_at) return 'Ditutup';
    return new Date(e.starts_at).getTime() > now ? 'Akan datang' : 'Berlangsung';
}

/** Kegiatan dalam 24 jam ke depan tanpa status/izin (aturan sama dengan app). */
export function reminders(events: MemberEvent[], now = Date.now()) {
    return events.filter((e) => {
        const t = new Date(e.starts_at).getTime();
        return !e.closed_at && !e.my_status && !e.excuse && t > now && t - now <= 24 * 3600_000;
    });
}

function ExcuseDialog({ event, onClose }: { event: MemberEvent; onClose: () => void }) {
    const form = useForm<{ event_id: number; type: string; reason: string; attachment: File | null }>({
        event_id: event.id, type: event.excuse?.type ?? 'izin', reason: event.excuse?.reason ?? '', attachment: null,
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/saya/izin', { forceFormData: true, preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{event.excuse ? 'Ubah izin' : 'Ajukan izin'}</DialogTitle>
                    <DialogDescription>{event.title} · {fmtDateTime(event.starts_at)}</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <FormField label="Jenis" error={form.errors.type}>
                        <NativeSelect className="w-full" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                            <NativeSelectOption value="izin">Izin</NativeSelectOption>
                            <NativeSelectOption value="sakit">Sakit</NativeSelectOption>
                        </NativeSelect>
                    </FormField>
                    <FormField label="Alasan" error={form.errors.reason}>
                        <Textarea rows={3} required maxLength={1000} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </FormField>
                    <FormField label="Bukti (opsional)" hint="Foto/gambar JPG, PNG, atau PDF, maks. 5 MB." error={form.errors.attachment}>
                        <Input type="file" accept="image/jpeg,image/png,application/pdf" onChange={(e) => form.setData('attachment', e.target.files?.[0] ?? null)} />
                    </FormField>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Batal</Button>
                        <Button type="submit" disabled={form.processing}>{form.processing ? 'Mengirim…' : 'Kirim'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function PortalEvents({ events, announcements }: { events: MemberEvent[]; announcements: Announcement[] }) {
    const [excuseFor, setExcuseFor] = useState<MemberEvent | null>(null);
    const soon = reminders(events);

    return (
        <PortalLayout title="Kegiatan">
            <Head title="Kegiatan" />
            {soon.map((e) => (
                <Alert key={`r${e.id}`}>
                    <BellRing />
                    <AlertTitle>Pengingat: {e.title}</AlertTitle>
                    <AlertDescription>{[fmtDateTime(e.starts_at), e.location, 'bawa kartu anggota'].filter(Boolean).join(' · ')}</AlertDescription>
                </Alert>
            ))}
            {announcements.map((a) => (
                <Card key={`a${a.id}`} className="gap-2">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">{a.pinned && <Pin className="size-4" aria-label="Disematkan" />}{a.title}</CardTitle>
                        <CardDescription>{[fmtDate(a.created_at), a.division && `Divisi ${a.division}`].filter(Boolean).join(' · ')}</CardDescription>
                    </CardHeader>
                    <CardContent className="text-sm whitespace-pre-line">{a.body}</CardContent>
                </Card>
            ))}
            {events.length === 0 && (
                <Card><CardHeader><CardTitle className="text-base">Belum ada kegiatan</CardTitle><CardDescription>Kegiatan yang Anda ikuti akan muncul di sini.</CardDescription></CardHeader></Card>
            )}
            <ul className="grid gap-3" aria-label="Daftar kegiatan">
                {events.map((e) => (
                    <li key={e.id}>
                        <Card className="gap-3 py-4">
                            <CardHeader className="px-4">
                                <CardTitle className="text-base">{e.title}</CardTitle>
                                <CardDescription className="flex flex-wrap gap-x-3 gap-y-1">
                                    <span className="inline-flex items-center gap-1"><CalendarDays className="size-3.5" />{fmtDate(e.starts_at)} {fmtTime(e.starts_at)}</span>
                                    {e.location && <span className="inline-flex items-center gap-1"><MapPin className="size-3.5" />{e.location}</span>}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-wrap items-center gap-2 px-4">
                                <StatusBadge status={e.my_status ?? e.excuse?.status ?? (e.closed_at ? 'closed' : 'open')} label={statusOf(e)} />
                                {!e.is_mandatory && <span className="text-xs text-muted-foreground">tidak wajib</span>}
                                {e.excuse?.review_note && <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">{e.excuse.review_note}</span>}
                                {e.can_request_excuse && (
                                    <Button size="sm" variant="outline" className="ml-auto" onClick={() => setExcuseFor(e)}>
                                        {e.excuse?.status === 'pending' ? 'Ubah izin' : 'Ajukan izin'}
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    </li>
                ))}
            </ul>
            {excuseFor && <ExcuseDialog event={excuseFor} onClose={() => setExcuseFor(null)} />}
        </PortalLayout>
    );
}
