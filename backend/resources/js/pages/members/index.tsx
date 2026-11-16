import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search, Upload } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { DataTable } from '@/components/data-table';
import { PageTabs } from '@/components/page-tabs';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { Paginated } from '@/types';

type Row = { id: number; member_no: string; name: string; cohort: string | null; division: string | null; status: string | null; has_card: boolean };

interface Props {
    tab: string; members: Paginated<Row>; divisions: { id: number; name: string }[]; term: { name: string };
    filters: { q: string | null; division_id: string | null };
}

export default function Members({ tab, members, divisions, term, filters }: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const [division, setDivision] = useState(filters.division_id ?? '');
    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get('/anggota', { tab, q: q || undefined, division_id: division || undefined }, { preserveState: true });
    };

    return (
        <AppLayout title="Anggota" actions={
            <>
                <Button variant="outline" render={<Link href="/anggota/impor" />}><Upload /> Impor CSV</Button>
                <Button render={<Link href="/anggota/create" />}><Plus /> Tambah Anggota</Button>
            </>
        }>
            <Head title="Anggota" />
            <p className="text-sm text-muted-foreground">Periode {term.name}</p>
            <PageTabs tabs={{ aktif: 'Aktif', alumni: 'Alumni', semua: 'Semua' }} current={tab} />
            <form onSubmit={apply} className="flex flex-wrap items-center gap-2">
                <div className="relative">
                    <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input className="w-64 pl-8" placeholder="Cari nama / nomor anggota" value={q} onChange={(e) => setQ(e.target.value)} />
                </div>
                <NativeSelect value={division} onChange={(e) => setDivision(e.target.value)}>
                    <NativeSelectOption value="">Semua divisi</NativeSelectOption>
                    {divisions.map((d) => <NativeSelectOption key={d.id} value={d.id}>{d.name}</NativeSelectOption>)}
                </NativeSelect>
                <Button type="submit" variant="secondary">Terapkan</Button>
            </form>
            <DataTable head={['No. Anggota', 'Nama', 'Divisi', 'Angkatan', 'Status', 'Kartu']} count={members.data.length} empty="Tidak ada anggota.">
                {members.data.map((m) => (
                    <TableRow key={m.id}>
                        <TableCell className="tabular-nums">{m.member_no}</TableCell>
                        <TableCell><Link href={`/anggota/${m.id}`} className="font-medium hover:underline">{m.name}</Link></TableCell>
                        <TableCell>{m.division ?? '—'}</TableCell>
                        <TableCell>{m.cohort ?? '—'}</TableCell>
                        <TableCell>{m.status ? <StatusBadge status={m.status} /> : <span className="text-xs text-muted-foreground">Bukan anggota periode ini</span>}</TableCell>
                        <TableCell>{m.has_card ? <StatusBadge status="active" /> : <span className="text-xs text-muted-foreground">Belum ada</span>}</TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <Pager page={members} />
        </AppLayout>
    );
}
