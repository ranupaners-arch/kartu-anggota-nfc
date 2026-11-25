import { Head } from '@inertiajs/react';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/data-table';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDateTime } from '@/lib/format';
import type { Paginated } from '@/types';

type D = { id: number; name: string; last_seen_at: string | null; revoked_at: string | null; user: { name: string; email: string } | null };

export default function Devices({ devices }: { devices: Paginated<D> }) {
    return (
        <AppLayout title="Perangkat Petugas">
            <Head title="Perangkat Petugas" />
            <p className="text-sm text-muted-foreground">Mencabut akses langsung mengeluarkan perangkat.</p>
            <DataTable head={['Perangkat', 'Pengguna', 'Terakhir aktif', 'Status', '']} count={devices.data.length} empty="Belum ada perangkat.">
                {devices.data.map((d) => (
                    <TableRow key={d.id}>
                        <TableCell className="font-medium">{d.name}</TableCell>
                        <TableCell>{d.user?.name}<div className="text-xs text-muted-foreground">{d.user?.email}</div></TableCell>
                        <TableCell>{fmtDateTime(d.last_seen_at)}</TableCell>
                        <TableCell><StatusBadge status={d.revoked_at ? 'revoked' : 'active'} /></TableCell>
                        <TableCell className="text-right">{!d.revoked_at && (
                            <ConfirmAction size="sm" destructive title={`Cabut akses "${d.name}"?`} description="Perangkat langsung keluar; scan offline darinya ditolak." href={`/perangkat/${d.id}/cabut`} confirmLabel="Cabut akses">Cabut akses</ConfirmAction>
                        )}</TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <Pager page={devices} />
        </AppLayout>
    );
}
