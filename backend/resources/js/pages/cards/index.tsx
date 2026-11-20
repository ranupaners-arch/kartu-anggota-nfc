import { Head, Link, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { type CardRow, CardsTable } from '@/components/cards-table';
import { ConfirmAction } from '@/components/confirm-action';
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

type Req = {
    id: number; reason: 'hilang' | 'rusak'; note: string | null; status: 'pending' | 'approved' | 'rejected'; created_at: string; reviewed_at: string | null; review_note: string | null;
    member: { id: number; member_no: string; name: string; self_card_writes_left: number } | null; reviewer: { name: string } | null;
};

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
                        <TableCell>{r.member ? <Link href={`/anggota/${r.member.id}?tab=kartu`} className="font-medium hover:underline">{r.member.name}</Link> : '—'}<div className="text-xs text-muted-foreground">{r.member?.member_no}</div></TableCell>
                        <TableCell className="capitalize">{r.reason}</TableCell>
                        <TableCell className="max-w-xs whitespace-normal">{r.note ?? '—'}</TableCell>
                        <TableCell><StatusBadge status={r.status} />{r.reviewer && <div className="text-xs text-muted-foreground">{r.reviewer.name}</div>}</TableCell>
                        <TableCell>
                            {r.status === 'pending' ? (
                                <div className="flex gap-1.5">
                                    <ConfirmAction size="sm" variant="default" title={`Setujui kartu pengganti ${r.member?.name ?? ''}?`} description="Anggota mendapat 1 kesempatan mendaftarkan kartu NFC baru lewat app-nya. Kartu aktif lamanya diblokir saat kartu baru didaftarkan." href={`/kartu/permintaan/${r.id}`} data={{ action: 'approve' }} confirmLabel="Setujui">Setujui</ConfirmAction>
                                    <ConfirmAction size="sm" destructive title="Tolak permintaan?" description="Anggota tetap bisa absen dengan QR (sesuai kebijakan denda)." href={`/kartu/permintaan/${r.id}`} data={{ action: 'reject' }} confirmLabel="Tolak">Tolak</ConfirmAction>
                                </div>
                            ) : <span className="text-xs text-muted-foreground">{fmtDateTime(r.reviewed_at)}</span>}
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
                                <TableCell>{r.member ? `${r.member.name} (${r.member.member_no})` : '—'}</TableCell>
                                <TableCell>{r.card_status ? <StatusBadge status={r.card_status} /> : '—'}</TableCell>
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
