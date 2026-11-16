import { Head, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { DataTable } from '@/components/data-table';
import { Pager } from '@/components/pager';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDateTime } from '@/lib/format';
import type { Paginated } from '@/types';

type L = { id: number; user_id: number | null; action: string; entity: string | null; entity_id: number | null; payload: Record<string, unknown> | null; ip: string | null; created_at: string };
type F = { action: string | null; user_id: string | null; from: string | null; to: string | null };

export default function Logs({ logs, users, filters }: { logs: Paginated<L>; users: Record<number, string>; filters: F }) {
    const [f, setF] = useState({ action: filters.action ?? '', user_id: filters.user_id ?? '', from: filters.from ?? '', to: filters.to ?? '' });
    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get('/log', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true });
    };

    return (
        <AppLayout title="Log Aktivitas">
            <Head title="Log Aktivitas" />
            <form onSubmit={apply} className="flex flex-wrap items-center gap-2">
                <Input className="w-56" placeholder="Aksi, mis. card. atau excuse" value={f.action} onChange={(e) => setF({ ...f, action: e.target.value })} />
                <NativeSelect value={f.user_id} onChange={(e) => setF({ ...f, user_id: e.target.value })}>
                    <NativeSelectOption value="">Semua pengguna</NativeSelectOption>
                    {Object.entries(users).map(([id, name]) => <NativeSelectOption key={id} value={id}>{name}</NativeSelectOption>)}
                </NativeSelect>
                <Input type="date" className="w-auto" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
                <span className="text-muted-foreground">–</span>
                <Input type="date" className="w-auto" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} />
                <Button type="submit" variant="secondary">Terapkan</Button>
            </form>
            <DataTable head={['Waktu', 'Pengguna', 'Aksi', 'Objek', 'Detail', 'IP']} count={logs.data.length} empty="Tidak ada catatan.">
                {logs.data.map((l) => (
                    <TableRow key={l.id}>
                        <TableCell className="whitespace-nowrap">{fmtDateTime(l.created_at)}</TableCell>
                        <TableCell>{(l.user_id && users[l.user_id]) ?? '—'}</TableCell>
                        <TableCell><code className="text-xs">{l.action}</code></TableCell>
                        <TableCell>{l.entity} #{l.entity_id}</TableCell>
                        <TableCell className="max-w-md truncate font-mono text-xs text-muted-foreground" title={l.payload ? JSON.stringify(l.payload) : ''}>{l.payload ? JSON.stringify(l.payload) : ''}</TableCell>
                        <TableCell className="text-xs">{l.ip}</TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <Pager page={logs} />
        </AppLayout>
    );
}
