import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/data-table';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { roleLabel } from '@/lib/format';
import type { Paginated, SharedProps } from '@/types';

type U = { id: number; name: string; email: string; role: string; disabled_at: string | null; division: { name: string } | null; member: { member_no: string } | null };

export default function Users({ users, filters }: { users: Paginated<U>; filters: { role: string | null } }) {
    const me = usePage<SharedProps>().props.auth?.user.id;
    return (
        <AppLayout title="Pengguna & Peran" actions={<Button render={<Link href="/pengguna/create" />}><Plus /> Tambah Pengguna</Button>}>
            <Head title="Pengguna & Peran" />
            <NativeSelect value={filters.role ?? ''} onChange={(e) => router.get('/pengguna', { role: e.target.value || undefined }, { preserveState: true })}>
                <NativeSelectOption value="">Semua peran</NativeSelectOption>
                {Object.entries(roleLabel).map(([v, l]) => <NativeSelectOption key={v} value={v}>{l}</NativeSelectOption>)}
            </NativeSelect>
            <DataTable head={['Nama', 'Email', 'Peran', 'Divisi', 'Anggota', 'Status', 'Tindakan']} count={users.data.length}>
                {users.data.map((u) => (
                    <TableRow key={u.id}>
                        <TableCell><Link href={`/pengguna/${u.id}/edit`} className="font-medium hover:underline">{u.name}</Link></TableCell>
                        <TableCell>{u.email}</TableCell><TableCell>{roleLabel[u.role]}</TableCell>
                        <TableCell>{u.division?.name ?? '—'}</TableCell><TableCell className="tabular-nums">{u.member?.member_no ?? '—'}</TableCell>
                        <TableCell><StatusBadge status={u.disabled_at ? 'disabled' : 'active'} /></TableCell>
                        <TableCell className="space-x-1.5">
                            {u.id !== me && (
                                <>
                                    <ConfirmAction size="sm" title={`Reset password ${u.email}?`} description="Password sementara baru akan ditampilkan sekali, dan pengguna keluar dari semua perangkat." href={`/pengguna/${u.id}/reset-password`} confirmLabel="Reset">Reset password</ConfirmAction>
                                    <ConfirmAction size="sm" destructive={!u.disabled_at} title={u.disabled_at ? 'Aktifkan akun?' : 'Nonaktifkan akun?'}
                                        description={u.disabled_at ? 'Pengguna bisa login lagi.' : 'Pengguna tidak bisa login dan keluar dari semua perangkat.'}
                                        href={`/pengguna/${u.id}/status`} confirmLabel={u.disabled_at ? 'Aktifkan' : 'Nonaktifkan'}>
                                        {u.disabled_at ? 'Aktifkan' : 'Nonaktifkan'}
                                    </ConfirmAction>
                                </>
                            )}
                        </TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <Pager page={users} />
        </AppLayout>
    );
}
