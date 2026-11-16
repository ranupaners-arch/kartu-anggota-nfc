import { Head, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { type CardRow, CardsTable } from '@/components/cards-table';
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

export default function Cards({ tab, items, filters }: { tab: string; items: Paginated<CardRow | Rejected>; filters: { q: string | null; status: string | null } }) {
    const [q, setQ] = useState(filters.q ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const apply = (e: FormEvent) => { e.preventDefault(); router.get('/kartu', { tab, q: q || undefined, status: status || undefined }, { preserveState: true }); };

    return (
        <AppLayout title="Kartu">
            <Head title="Kartu" />
            <PageTabs tabs={{ semua: 'Semua Kartu', ditolak: 'Percobaan Ditolak' }} current={tab} />
            {tab === 'ditolak' ? (
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
