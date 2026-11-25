import { Head, Link, router, useForm } from '@inertiajs/react';
import { FileText, IdCard, Pencil, ShieldAlert, Trash2, UserRound } from 'lucide-react';
import { type ChangeEvent, type FormEvent, useState } from 'react';
import { type CardRow, CardsTable } from '@/components/cards-table';
import { MemberCard, type MemberCardInfo } from '@/components/member-card';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/data-table';
import { DutyCard, type MemberDuty } from '@/components/duty-card';
import { PageTabs } from '@/components/page-tabs';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { FormField } from '@/components/form-field';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDate, fmtDateTime } from '@/lib/format';
import type { Paginated } from '@/types';

type Member = { id: number; member_no: string; name: string; email: string | null; phone: string | null; cohort: string | null; photo_url: string | null };
type Ms = { id: number; term_id: number; term: string | null; division: string | null; role: string | null; status: string };
type Att = { id: number; status: string; source: string; voided_at: string | null; event: { title: string; starts_at: string } | null };
type CardReq = { id: number; reason: string; note: string | null; status: string; resolution: string | null; review_note: string | null; created_at: string; reviewed_at: string | null; reviewer: string | null };
type Privacy = { consentedAt: string | null; consentedBy: string | null; anonymizedAt: string | null; blocker: string | null };

/** Hapus data pribadi (permanen): ketik nomor anggota + alasan. Kehadiran tetap tercatat di rekap. */
function AnonymizeDialog({ member }: { member: Member }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ confirm: '', reason: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/anggota/${member.id}/hapus-data`, { preserveScroll: true, onSuccess: () => setOpen(false) });
    };
    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button variant="destructive" size="sm" />}><ShieldAlert /> Hapus data pribadi</AlertDialogTrigger>
            <AlertDialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <AlertDialogHeader>
                        <AlertDialogTitle>Hapus data pribadi {member.name}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Nama, nomor anggota, kontak, foto, alasan &amp; lampiran izin, dan akun login anggota ini dihapus permanen dan tidak bisa dikembalikan.
                            Catatan kehadirannya tetap dihitung di rekap sebagai “Anggota terhapus”.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <FormField label="Alasan (tercatat di log audit)" error={form.errors.reason}>
                        <Textarea rows={2} required value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="Mis. permintaan anggota lewat email, 19 Nov 2026" />
                    </FormField>
                    <FormField label={`Ketik nomor anggota (${member.member_no}) untuk konfirmasi`} error={form.errors.confirm}>
                        <Input required autoComplete="off" value={form.data.confirm} onChange={(e) => form.setData('confirm', e.target.value)} />
                    </FormField>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <Button type="submit" variant="destructive" disabled={form.processing || form.data.confirm !== member.member_no}>Hapus permanen</Button>
                    </AlertDialogFooter>
                </form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function PrivacyCard({ member, privacy }: { member: Member; privacy: Privacy }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Privasi</CardTitle>
                <CardDescription>Persetujuan pemrosesan data dan hak hapus data pribadi.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                    {privacy.consentedAt
                        ? <span>Persetujuan tercatat {fmtDateTime(privacy.consentedAt)}{privacy.consentedBy && <> oleh {privacy.consentedBy}</>}.</span>
                        : <>
                            <span className="text-amber-700 dark:text-amber-400">Persetujuan belum tercatat.</span>
                            <ConfirmAction size="sm" title="Catat persetujuan?" description="Pastikan anggota sudah menyetujui data dirinya (termasuk foto) diproses organisasi untuk keperluan absensi." href={`/anggota/${member.id}/persetujuan`} confirmLabel="Catat">Catat persetujuan</ConfirmAction>
                        </>}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {privacy.blocker
                        ? <span className="text-muted-foreground">Hapus data pribadi: {privacy.blocker}</span>
                        : <AnonymizeDialog member={member} />}
                </div>
            </CardContent>
        </Card>
    );
}

export default function MemberShow({ member, tab, memberships, cards, attendances, privacy, canManage, cardPreview, cardRequests, selfCardWritesLeft, duty }: {
    member: Member; tab: string; memberships: Ms[]; cards: CardRow[]; attendances: Paginated<Att> | null; privacy: Privacy; canManage: boolean; cardPreview: MemberCardInfo; cardRequests: CardReq[]; selfCardWritesLeft: number; duty: MemberDuty | null;
}) {
    const anonymized = Boolean(privacy.anonymizedAt);
    const editable = canManage && !anonymized;
    const upload = (e: ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) router.post(`/anggota/${member.id}/foto`, { photo: file }, { forceFormData: true, preserveScroll: true });
    };

    return (
        <AppLayout title={member.name} actions={!editable ? undefined :
            <>
                <Button variant="outline" render={<Link href={`/anggota/${member.id}/edit`} />}><Pencil /> Ubah</Button>
                <ConfirmAction destructive title="Hapus anggota?" description="Data disimpan sebagai arsip (soft delete) dan kartu aktifnya diblokir." href={`/anggota/${member.id}`} method="delete" confirmLabel="Hapus">
                    <Trash2 /> Hapus
                </ConfirmAction>
            </>
        }>
            <Head title={member.name} />
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm text-muted-foreground">No. anggota {member.member_no}</p>
                {!anonymized && (
                    <div className="flex flex-wrap gap-2">
                        {editable && <Button variant="outline" size="sm" render={<a href={`/anggota/kartu-cetak?ids[]=${member.id}&format=kartu`} target="_blank" rel="noreferrer" />}><IdCard /> Cetak kartu</Button>}
                        <Button variant="outline" size="sm" render={<a href={`/anggota/${member.id}/rapor`} target="_blank" rel="noreferrer" />}><FileText /> Rapor PDF</Button>
                    </div>
                )}
            </div>
            {anonymized && (
                <Alert>
                    <ShieldAlert />
                    <AlertTitle>Data pribadi sudah dihapus</AlertTitle>
                    <AlertDescription>Dihapus {fmtDateTime(privacy.anonymizedAt)}. Catatan kehadiran tetap dihitung di rekap; data ini tidak bisa diubah.</AlertDescription>
                </Alert>
            )}
            <PageTabs tabs={{ profil: 'Profil', riwayat: 'Riwayat Hadir', kartu: 'Kartu' }} current={tab} />
            {tab === 'profil' && (
                <div className="grid gap-4 lg:grid-cols-[220px_1fr]">
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3">
                            {member.photo_url
                                ? <img src={member.photo_url} alt={`Foto ${member.name}`} className="aspect-square w-40 rounded-lg object-cover" />
                                : <div className="flex aspect-square w-40 items-center justify-center rounded-lg bg-muted"><UserRound className="size-12 text-muted-foreground" /></div>}
                            {editable && (
                                <Button variant="outline" size="sm" render={<label className="cursor-pointer" />}>
                                    Unggah foto<input type="file" accept="image/*" className="sr-only" onChange={upload} />
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                    <div className="grid gap-4">
                        <Card>
                            <CardHeader><CardTitle>Data diri</CardTitle></CardHeader>
                            <CardContent className="grid gap-2 text-sm sm:grid-cols-3">
                                <div><div className="text-muted-foreground">Email</div>{member.email ?? '-'}</div>
                                <div><div className="text-muted-foreground">Telepon</div>{member.phone ?? '-'}</div>
                                <div><div className="text-muted-foreground">Angkatan</div>{member.cohort ?? '-'}</div>
                            </CardContent>
                        </Card>
                        <DataTable head={['Periode', 'Divisi', 'Jabatan', 'Status']} count={memberships.length} empty="Belum ada keanggotaan.">
                            {memberships.map((m) => (
                                <TableRow key={m.id}><TableCell>{m.term} {!anonymized && <a href={`/anggota/${member.id}/rapor?periode=${m.term_id}`} target="_blank" rel="noreferrer" className="ml-1 text-xs text-primary hover:underline">rapor</a>}</TableCell><TableCell>{m.division ?? '-'}</TableCell><TableCell>{m.role ?? '-'}</TableCell><TableCell><StatusBadge status={m.status} /></TableCell></TableRow>
                            ))}
                        </DataTable>
                        {editable && duty && <DutyCard memberId={member.id} memberName={member.name} duty={duty} />}
                        {editable && <PrivacyCard member={member} privacy={privacy} />}
                    </div>
                </div>
            )}
            {tab === 'riwayat' && attendances && (
                <>
                    <DataTable head={['Kegiatan', 'Tanggal', 'Status', 'Sumber']} count={attendances.data.length} empty="Belum ada riwayat.">
                        {attendances.data.map((a) => (
                            <TableRow key={a.id}><TableCell>{a.event?.title}</TableCell><TableCell>{fmtDate(a.event?.starts_at)}</TableCell>
                                <TableCell><StatusBadge status={a.voided_at ? 'void' : a.status} /></TableCell><TableCell className="uppercase text-muted-foreground">{a.source}</TableCell></TableRow>
                        ))}
                    </DataTable>
                    <Pager page={attendances} />
                </>
            )}
            {tab === 'kartu' && (
                <>
                    <div className="max-w-sm"><MemberCard info={cardPreview} /></div>
                    <p className="text-sm text-muted-foreground">Kartu baru diterbitkan lewat app petugas (mode Issue). Kartu lama otomatis diblokir.</p>
                    <CardsTable cards={cards} showMember={false} />
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Daftar kartu sendiri & laporan</CardTitle>
                            <CardDescription>Sisa jatah anggota mendaftarkan kartu NFC sendiri lewat app: <b className="text-foreground">{selfCardWritesLeft}×</b>.</CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {editable && selfCardWritesLeft < 3 && (
                                <div><ConfirmAction size="sm" title="Tambah 1 jatah daftar kartu sendiri?" description="Anggota bisa mendaftarkan satu kartu NFC lagi lewat app-nya; kartu aktif lamanya diblokir saat kartu baru didaftarkan." href={`/anggota/${member.id}/jatah-kartu`} confirmLabel="Tambah jatah">Tambah jatah</ConfirmAction></div>
                            )}
                            {cardRequests.length === 0 ? <p className="text-sm text-muted-foreground">Belum ada laporan kartu.</p> : (
                                <ul className="divide-y text-sm">
                                    {cardRequests.map((q) => (
                                        <li key={q.id} className="flex flex-wrap items-start gap-x-3 gap-y-1 py-2">
                                            <span className="min-w-0 flex-1">
                                                <b>{{ hilang: 'Kartu hilang', rusak: 'Kartu rusak', tanpa_nfc: 'HP tanpa NFC' }[q.reason] ?? q.reason}</b> · {fmtDateTime(q.created_at)}
                                                {q.note && <span className="block text-muted-foreground">“{q.note}”</span>}
                                                {q.review_note && <span className="block text-muted-foreground">Catatan pengurus: {q.review_note}</span>}
                                            </span>
                                            <span className="text-right text-xs text-muted-foreground">
                                                <StatusBadge status={q.status} />
                                                {q.resolution && <span className="block">{q.resolution === 'admin' ? 'Pengurus terbitkan' : 'Daftar sendiri'}</span>}
                                                {q.reviewer && <span className="block">{q.reviewer}</span>}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <Link href="/kartu?tab=pengganti" className="text-sm text-primary underline-offset-4 hover:underline">Semua permintaan pengganti →</Link>
                        </CardContent>
                    </Card>
                </>
            )}
        </AppLayout>
    );
}
