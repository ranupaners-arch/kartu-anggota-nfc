import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Trash2, UserRound } from 'lucide-react';
import { type ChangeEvent } from 'react';
import { type CardRow, CardsTable } from '@/components/cards-table';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/data-table';
import { PageTabs } from '@/components/page-tabs';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDate } from '@/lib/format';
import type { Paginated } from '@/types';

type Member = { id: number; member_no: string; name: string; email: string | null; phone: string | null; cohort: string | null; photo_url: string | null };
type Ms = { id: number; term: string | null; division: string | null; role: string | null; status: string };
type Att = { id: number; status: string; source: string; voided_at: string | null; event: { title: string; starts_at: string } | null };

export default function MemberShow({ member, tab, memberships, cards, attendances }: {
    member: Member; tab: string; memberships: Ms[]; cards: CardRow[]; attendances: Paginated<Att> | null;
}) {
    const upload = (e: ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) router.post(`/anggota/${member.id}/foto`, { photo: file }, { forceFormData: true, preserveScroll: true });
    };

    return (
        <AppLayout title={member.name} actions={
            <>
                <Button variant="outline" render={<Link href={`/anggota/${member.id}/edit`} />}><Pencil /> Ubah</Button>
                <ConfirmAction destructive title="Hapus anggota?" description="Data disimpan sebagai arsip (soft delete) dan kartu aktifnya diblokir." href={`/anggota/${member.id}`} method="delete" confirmLabel="Hapus">
                    <Trash2 /> Hapus
                </ConfirmAction>
            </>
        }>
            <Head title={member.name} />
            <p className="text-sm text-muted-foreground">No. anggota {member.member_no}</p>
            <PageTabs tabs={{ profil: 'Profil', riwayat: 'Riwayat Hadir', kartu: 'Kartu' }} current={tab} />
            {tab === 'profil' && (
                <div className="grid gap-4 lg:grid-cols-[220px_1fr]">
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3">
                            {member.photo_url
                                ? <img src={member.photo_url} alt={`Foto ${member.name}`} className="aspect-square w-40 rounded-lg object-cover" />
                                : <div className="flex aspect-square w-40 items-center justify-center rounded-lg bg-muted"><UserRound className="size-12 text-muted-foreground" /></div>}
                            <Button variant="outline" size="sm" render={<label className="cursor-pointer" />}>
                                Unggah foto<input type="file" accept="image/*" className="sr-only" onChange={upload} />
                            </Button>
                        </CardContent>
                    </Card>
                    <div className="grid gap-4">
                        <Card>
                            <CardHeader><CardTitle>Data diri</CardTitle></CardHeader>
                            <CardContent className="grid gap-2 text-sm sm:grid-cols-3">
                                <div><div className="text-muted-foreground">Email</div>{member.email ?? '—'}</div>
                                <div><div className="text-muted-foreground">Telepon</div>{member.phone ?? '—'}</div>
                                <div><div className="text-muted-foreground">Angkatan</div>{member.cohort ?? '—'}</div>
                            </CardContent>
                        </Card>
                        <DataTable head={['Periode', 'Divisi', 'Jabatan', 'Status']} count={memberships.length} empty="Belum ada keanggotaan.">
                            {memberships.map((m) => (
                                <TableRow key={m.id}><TableCell>{m.term}</TableCell><TableCell>{m.division ?? '—'}</TableCell><TableCell>{m.role ?? '—'}</TableCell><TableCell><StatusBadge status={m.status} /></TableCell></TableRow>
                            ))}
                        </DataTable>
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
                    <p className="text-sm text-muted-foreground">Kartu baru diterbitkan lewat app petugas (mode Issue). Menerbitkan kartu baru otomatis memblokir kartu lama.</p>
                    <CardsTable cards={cards} showMember={false} />
                </>
            )}
        </AppLayout>
    );
}
