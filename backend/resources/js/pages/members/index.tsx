import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search, Upload } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { BulkBar, SelectAll, SelectRow, useSelection, type Selection } from '@/components/bulk-select';
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

type Row = { id: number; member_no: string; name: string; cohort: string | null; division: string | null; status: string | null; has_card: boolean; deleted_at?: string };

interface Props {
    tab: string; members: Paginated<Row>; divisions: { id: number; name: string }[]; term: { name: string };
    filters: { q: string | null; division_id: string | null }; canManage: boolean;
}

const statusLabels: Record<string, string> = { active: 'Aktif', inactive: 'Tidak aktif', alumni: 'Alumni', applicant: 'Pendaftar' };

/** Pindah divisi / ubah status / arsipkan anggota terpilih (Super Admin). */
function MemberBulkActions({ sel, divisions }: { sel: Selection; divisions: { id: number; name: string }[] }) {
    const [division, setDivision] = useState('');
    const [status, setStatus] = useState('alumni');
    const n = sel.count;
    const divisionName = division ? divisions.find((d) => String(d.id) === division)?.name : 'Tanpa divisi';
    return (
        <>
            <div className="flex items-center gap-1.5">
                <NativeSelect size="sm" value={division} onChange={(e) => setDivision(e.target.value)} aria-label="Divisi tujuan">
                    <NativeSelectOption value="">Tanpa divisi</NativeSelectOption>
                    {divisions.map((d) => <NativeSelectOption key={d.id} value={d.id}>{d.name}</NativeSelectOption>)}
                </NativeSelect>
                <ConfirmAction size="sm" title={`Pindahkan ${n} anggota ke ${divisionName}?`} description="Berlaku untuk keanggotaan periode berjalan." href="/anggota/massal"
                    data={{ ids: sel.ids, action: 'division', value: division || null }} onSuccess={sel.clear} confirmLabel="Pindahkan">Pindahkan</ConfirmAction>
            </div>
            <div className="flex items-center gap-1.5">
                <NativeSelect size="sm" value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status baru">
                    {Object.entries(statusLabels).map(([v, l]) => <NativeSelectOption key={v} value={v}>{l}</NativeSelectOption>)}
                </NativeSelect>
                <ConfirmAction size="sm" title={`Ubah status ${n} anggota menjadi ${statusLabels[status]}?`} description="Berlaku untuk keanggotaan periode berjalan." href="/anggota/massal"
                    data={{ ids: sel.ids, action: 'status', value: status }} onSuccess={sel.clear} confirmLabel="Ubah status">Ubah status</ConfirmAction>
            </div>
            <ConfirmAction size="sm" destructive title={`Arsipkan ${n} anggota?`} description="Anggota pindah ke tab Arsip dan kartu aktifnya diblokir. Bisa dipulihkan satu per satu dari Arsip."
                href="/anggota/massal" data={{ ids: sel.ids, action: 'archive' }} onSuccess={sel.clear} confirmLabel="Arsipkan">Arsipkan</ConfirmAction>
        </>
    );
}

export default function Members({ tab, members, divisions, term, filters, canManage }: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const [division, setDivision] = useState(filters.division_id ?? '');
    const bulk = canManage && tab !== 'arsip';
    const sel = useSelection(bulk ? members.data.map((m) => m.id) : []);
    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get('/anggota', { tab, q: q || undefined, division_id: division || undefined }, { preserveState: true });
    };

    return (
        <AppLayout title="Anggota" actions={!canManage ? undefined :
            <>
                <Button variant="outline" render={<Link href="/anggota/impor" />}><Upload /> Impor CSV</Button>
                <Button render={<Link href="/anggota/create" />}><Plus /> Tambah Anggota</Button>
            </>
        }>
            <Head title="Anggota" />
            <p className="text-sm text-muted-foreground">Periode {term.name}{!canManage && ' · anggota divisi Anda'}</p>
            <PageTabs tabs={canManage ? { aktif: 'Aktif', alumni: 'Alumni', semua: 'Semua', arsip: 'Arsip' } : { aktif: 'Aktif', alumni: 'Alumni', semua: 'Semua' }} current={tab} />
            <form onSubmit={apply} className="flex flex-wrap items-center gap-2">
                <div className="relative">
                    <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input className="w-64 pl-8" placeholder="Cari nama / nomor anggota" value={q} onChange={(e) => setQ(e.target.value)} />
                </div>
                {divisions.length > 0 && (
                    <NativeSelect value={division} onChange={(e) => setDivision(e.target.value)}>
                        <NativeSelectOption value="">Semua divisi</NativeSelectOption>
                        {divisions.map((d) => <NativeSelectOption key={d.id} value={d.id}>{d.name}</NativeSelectOption>)}
                    </NativeSelect>
                )}
                <Button type="submit" variant="secondary">Terapkan</Button>
            </form>
            {tab === 'arsip' ? (
                <DataTable head={['No. Anggota', 'Nama', 'Angkatan', 'Dihapus', '']} count={members.data.length} empty="Arsip kosong.">
                    {members.data.map((m) => (
                        <TableRow key={m.id}>
                            <TableCell className="tabular-nums">{m.member_no}</TableCell><TableCell className="font-medium">{m.name}</TableCell>
                            <TableCell>{m.cohort ?? '—'}</TableCell><TableCell>{fmtDateTime(m.deleted_at)}</TableCell>
                            <TableCell className="text-right">
                                <ConfirmAction size="sm" title={`Pulihkan ${m.name}?`} description="Anggota kembali ke daftar. Kartu lamanya tetap diblokir; terbitkan kartu baru bila perlu." href={`/anggota/${m.id}/pulihkan`} confirmLabel="Pulihkan">Pulihkan</ConfirmAction>
                            </TableCell>
                        </TableRow>
                    ))}
                </DataTable>
            ) : (
            <>
            <DataTable head={[...(bulk ? [<SelectAll key="all" sel={sel} />] : []), 'No. Anggota', 'Nama', 'Divisi', 'Angkatan', 'Status', 'Kartu']} count={members.data.length} empty="Tidak ada anggota.">
                {members.data.map((m) => (
                    <TableRow key={m.id} data-state={sel.has(m.id) ? 'selected' : undefined}>
                        {bulk && <TableCell className="w-8"><SelectRow sel={sel} id={m.id} label={m.name} /></TableCell>}
                        <TableCell className="tabular-nums">{m.member_no}</TableCell>
                        <TableCell><Link href={`/anggota/${m.id}`} className="font-medium hover:underline">{m.name}</Link></TableCell>
                        <TableCell>{m.division ?? '—'}</TableCell>
                        <TableCell>{m.cohort ?? '—'}</TableCell>
                        <TableCell>{m.status ? <StatusBadge status={m.status} /> : <span className="text-xs text-muted-foreground">Bukan anggota periode ini</span>}</TableCell>
                        <TableCell>{m.has_card ? <StatusBadge status="active" /> : <span className="text-xs text-muted-foreground">Belum ada</span>}</TableCell>
                    </TableRow>
                ))}
            </DataTable>
            {bulk && <BulkBar sel={sel}><MemberBulkActions sel={sel} divisions={divisions} /></BulkBar>}
            </>
            )}
            <Pager page={members} />
        </AppLayout>
    );
}
