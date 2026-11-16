import { Head, router } from '@inertiajs/react';
import { Paperclip } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/data-table';
import { PageTabs } from '@/components/page-tabs';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDate } from '@/lib/format';
import type { Paginated } from '@/types';

type Excuse = {
    id: number; type: string; reason: string; status: string; review_note: string | null; attachment_path: string | null;
    member: { member_no: string; name: string } | null; event: { title: string; starts_at: string } | null;
};

function Review({ excuse }: { excuse: Excuse }) {
    const [note, setNote] = useState('');
    const send = (status: 'approved' | 'rejected') => router.patch(`/izin/${excuse.id}`, { status, note }, { preserveScroll: true });
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <Input className="h-7 w-36" placeholder="Catatan (opsional)" value={note} onChange={(e) => setNote(e.target.value)} />
            <Button size="sm" onClick={() => send('approved')}>Setujui</Button>
            <Button size="sm" variant="destructive" onClick={() => send('rejected')}>Tolak</Button>
        </div>
    );
}

export default function Excuses({ tab, excuses }: { tab: string; excuses: Paginated<Excuse> }) {
    return (
        <AppLayout title="Pengajuan Izin">
            <Head title="Pengajuan Izin" />
            <PageTabs tabs={{ menunggu: 'Menunggu', riwayat: 'Riwayat' }} current={tab} />
            <DataTable head={['Anggota', 'Kegiatan', 'Jenis', 'Alasan', 'Bukti', tab === 'menunggu' ? 'Tindakan' : 'Keputusan']} count={excuses.data.length} empty="Tidak ada pengajuan.">
                {excuses.data.map((x) => (
                    <TableRow key={x.id}>
                        <TableCell>{x.member?.name}<div className="text-xs text-muted-foreground">{x.member?.member_no}</div></TableCell>
                        <TableCell>{x.event?.title}<div className="text-xs text-muted-foreground">{fmtDate(x.event?.starts_at)}</div></TableCell>
                        <TableCell><StatusBadge status={x.type} /></TableCell>
                        <TableCell className="max-w-xs whitespace-normal">{x.reason}</TableCell>
                        <TableCell>{x.attachment_path
                            ? <a href={`/izin/${x.id}/bukti`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-primary hover:underline"><Paperclip className="size-3.5" /> Lihat</a>
                            : <span className="text-muted-foreground">—</span>}</TableCell>
                        <TableCell>{x.status === 'pending' ? <Review excuse={x} /> : (
                            <div><StatusBadge status={x.status} />{x.review_note && <div className="mt-1 text-xs text-muted-foreground">{x.review_note}</div>}</div>
                        )}</TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <Pager page={excuses} />
        </AppLayout>
    );
}
