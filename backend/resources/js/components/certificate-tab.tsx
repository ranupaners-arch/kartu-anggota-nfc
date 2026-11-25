import { router, useForm } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/data-table';
import { FormField } from '@/components/form-field';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { TableCell, TableRow } from '@/components/ui/table';
import type { Paginated } from '@/types';

type Item = { id: number; number: string; name: string; member_no: string | null; revoked_at: string | null; reason: string | null; auto: boolean };
export type CertificateData = {
    issued: boolean;
    form: { title: string; role: string; min_sessions: number; signer1_name: string; signer1_title: string; signer2_name: string; signer2_title: string };
    plan: { eligible: number; add: number; revoke: number; restore: number } | null;
    items: Paginated<Item>;
};

function RevokeForm({ id }: { id: number }) {
    const [reason, setReason] = useState('');
    const submit = (e: FormEvent) => { e.preventDefault(); router.post(`/kegiatan-sertifikat/${id}/cabut`, { reason }, { preserveScroll: true }); };
    return (
        <form onSubmit={submit} className="flex items-center gap-1.5">
            <Input className="h-7 w-32" placeholder="Alasan" aria-label="Alasan pencabutan" required minLength={3} maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} />
            <Button size="sm" variant="destructive" type="submit" disabled={reason.trim().length < 3}>Cabut</Button>
        </form>
    );
}

/** Sertifikat kegiatan: pengaturan, penerbitan, dan daftar. */
export function CertificateTab({ eventId, closed, sessions, data }: { eventId: number; closed: boolean; sessions: number; data: CertificateData }) {
    const form = useForm({ ...data.form, min_sessions: String(data.form.min_sessions) });
    const submit = (e: FormEvent) => { e.preventDefault(); form.put(`/kegiatan/${eventId}/sertifikat`, { preserveScroll: true }); };
    const p = data.plan;
    const pending = p && data.issued && p.add + p.revoke + p.restore > 0;
    const field = (key: 'title' | 'role' | 'signer1_name' | 'signer1_title' | 'signer2_name' | 'signer2_title', label: string, required = true) => (
        <FormField label={label} error={form.errors[key]}><Input required={required} maxLength={key === 'title' || key === 'role' ? 60 : 100} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} /></FormField>
    );
    return (
        <>
            {!closed && <Alert><AlertDescription>Sertifikat diterbitkan setelah kegiatan <b>ditutup</b>, supaya absensinya sudah final.</AlertDescription></Alert>}
            {pending && <Alert><AlertDescription>Absensi berubah sejak penerbitan terakhir: {p.add} perlu diterbitkan, {p.revoke} perlu dicabut, {p.restore} bisa dipulihkan. Klik <b>Terbitkan ulang</b>.</AlertDescription></Alert>}
            {closed && p && (
                <Card className="max-w-4xl">
                    <CardHeader>
                        <CardTitle>{data.issued ? 'Pengaturan sertifikat' : 'Terbitkan sertifikat'}</CardTitle>
                        <CardDescription>Untuk anggota berstatus Hadir atau Telat{sessions > 1 ? ' di jumlah sesi minimal' : ''}: {p.eligible} orang{sessions > 1 ? ` (dengan minimal ${data.form.min_sessions} sesi)` : ''}. Mengubah isi juga mengubah PDF yang sudah terbit.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="grid gap-4">
                            <div className="grid gap-4 sm:grid-cols-3">
                                {field('title', 'Judul')}{field('role', 'Sebagai')}
                                {sessions > 1 && <FormField label="Minimal sesi hadir" hint={`Dari ${sessions} sesi.`} error={form.errors.min_sessions}><Input type="number" min={1} max={sessions} required value={form.data.min_sessions} onChange={(e) => form.setData('min_sessions', e.target.value)} /></FormField>}
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {field('signer1_name', 'Penanda tangan 1')}{field('signer1_title', 'Jabatan')}
                                {field('signer2_name', 'Penanda tangan 2 (opsional)', false)}{field('signer2_title', 'Jabatan 2', false)}
                            </div>
                            <div><Button type="submit" disabled={form.processing}>{form.processing ? 'Memproses…' : data.issued ? 'Terbitkan ulang' : 'Terbitkan'}</Button></div>
                        </form>
                    </CardContent>
                </Card>
            )}
            {data.items.total > 0 && (
                <>
                    <DataTable head={['Nomor', 'Nama', 'Status', 'Tindakan']} count={data.items.data.length}>
                        {data.items.data.map((c) => (
                            <TableRow key={c.id}>
                                <TableCell className="tabular-nums">{c.number}</TableCell>
                                <TableCell>{c.name}<div className="text-xs text-muted-foreground">{c.member_no}</div></TableCell>
                                <TableCell><StatusBadge status={c.revoked_at ? 'revoked' : 'active'} label={c.revoked_at ? 'Dicabut' : 'Berlaku'} />{c.reason && <div className="max-w-xs text-xs whitespace-normal text-muted-foreground">{c.reason}</div>}</TableCell>
                                <TableCell>
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        {!c.revoked_at && <Button size="sm" variant="outline" render={<a href={`/kegiatan-sertifikat/${c.id}.pdf`} target="_blank" rel="noreferrer" aria-label={`PDF sertifikat ${c.name}`} />}><Download /> PDF</Button>}
                                        {!c.revoked_at && <RevokeForm id={c.id} />}
                                        {c.revoked_at && !c.auto && <ConfirmAction size="sm" title={`Berlakukan lagi sertifikat ${c.name}?`} description="Hanya bisa bila anggota masih memenuhi syarat kehadiran." href={`/kegiatan-sertifikat/${c.id}/pulihkan`} confirmLabel="Berlakukan">Pulihkan</ConfirmAction>}
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}
                    </DataTable>
                    <Pager page={data.items} />
                </>
            )}
        </>
    );
}
