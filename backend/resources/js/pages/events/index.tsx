import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { DataTable } from '@/components/data-table';
import { PageTabs } from '@/components/page-tabs';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDateTime, fmtTime } from '@/lib/format';
import type { Paginated } from '@/types';

type Row = {
    id: number; title: string; type: string; location: string | null; is_mandatory: boolean; closed_at: string | null;
    starts_at: string; checkin_opens_at: string; late_after: string; checkin_closes_at: string; present_count: number;
};

export default function EventsIndex({ tab, events }: { tab: string; events: Paginated<Row> }) {
    return (
        <AppLayout title="Kegiatan" actions={<Button render={<Link href="/kegiatan/create" />}><Plus /> Buat Kegiatan</Button>}>
            <Head title="Kegiatan" />
            <PageTabs tabs={{ berlangsung: 'Berlangsung', 'akan-datang': 'Akan Datang', selesai: 'Selesai' }} current={tab} />
            <DataTable head={['Kegiatan', 'Mulai', 'Check-in', 'Lokasi', 'Hadir', 'Status']} count={events.data.length} empty="Tidak ada kegiatan.">
                {events.data.map((e) => (
                    <TableRow key={e.id}>
                        <TableCell>
                            <Link href={`/kegiatan/${e.id}`} className="font-medium hover:underline">{e.title}</Link>
                            <div className="text-xs text-muted-foreground">{e.type}{!e.is_mandatory && ' · tidak wajib'}</div>
                        </TableCell>
                        <TableCell>{fmtDateTime(e.starts_at)}</TableCell>
                        <TableCell>
                            {fmtTime(e.checkin_opens_at)}–{fmtTime(e.checkin_closes_at)}
                            <div className="text-xs text-muted-foreground">telat setelah {fmtTime(e.late_after)}</div>
                        </TableCell>
                        <TableCell>{e.location ?? '—'}</TableCell>
                        <TableCell className="tabular-nums">{e.present_count}</TableCell>
                        <TableCell><StatusBadge status={e.closed_at ? 'closed' : 'open'} /></TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <Pager page={events} />
        </AppLayout>
    );
}
