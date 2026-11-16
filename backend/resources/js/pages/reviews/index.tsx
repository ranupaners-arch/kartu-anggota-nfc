import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { DataTable } from '@/components/data-table';
import { PageTabs } from '@/components/page-tabs';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDateTime } from '@/lib/format';
import type { Paginated } from '@/types';

type Att = { id: number; status: string; scanned_at: string | null; review_reason: string | null; member: { member_no: string; name: string } | null; event: { title: string } | null };
type Log = { id: number; created_at: string; entity_id: number; payload: { event_id?: number; device_id?: number } | null };

const reasons: Record<string, string> = { card_blocked_after_scan: 'Kartu diblokir setelah scan offline' };

function Actions({ a, canAccept }: { a: Att; canAccept: boolean }) {
    const [reason, setReason] = useState('');
    const send = (action: 'terima' | 'batalkan') => router.post(`/tinjauan/${a.id}/${action}`, { reason }, { preserveScroll: true });
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <Input className="h-7 w-32" placeholder="Alasan" value={reason} onChange={(e) => setReason(e.target.value)} />
            {canAccept && <Button size="sm" disabled={!reason} onClick={() => send('terima')}>Terima</Button>}
            <Button size="sm" variant="destructive" disabled={!reason} onClick={() => send('batalkan')}>Batalkan</Button>
        </div>
    );
}

export default function Reviews({ tab, items }: { tab: string; items: Paginated<Att | Log> }) {
    const help: Record<string, string> = {
        offline: 'Scan offline yang bermasalah saat disinkron, mis. kartu diblokir setelah scan. Terima bila sah, batalkan bila tidak.',
        override: 'Scan di luar jendela check-in yang di-override petugas dengan alasan.',
        anomali: 'Kartu yang sama di-tap di kegiatan/perangkat berbeda dalam < 60 detik. Periksa kemungkinan kartu digandakan.',
    };
    return (
        <AppLayout title="Perlu Ditinjau">
            <Head title="Perlu Ditinjau" />
            <PageTabs tabs={{ offline: 'Konflik Offline', override: 'Override', anomali: 'Anomali' }} current={tab} />
            <p className="text-sm text-muted-foreground">{help[tab]}</p>
            {tab === 'anomali' ? (
                <DataTable head={['Waktu', 'Kartu', 'Detail']} count={items.data.length} empty="Tidak ada anomali.">
                    {(items.data as Log[]).map((l) => (
                        <TableRow key={l.id}>
                            <TableCell>{fmtDateTime(l.created_at)}</TableCell><TableCell>#{l.entity_id}</TableCell>
                            <TableCell className="text-muted-foreground">Kegiatan #{l.payload?.event_id ?? '?'}, perangkat #{l.payload?.device_id ?? '?'}</TableCell>
                        </TableRow>
                    ))}
                </DataTable>
            ) : (
                <DataTable head={['Anggota', 'Kegiatan', 'Status', 'Waktu scan', 'Catatan', 'Tindakan']} count={items.data.length} empty="Tidak ada yang perlu ditinjau.">
                    {(items.data as Att[]).map((a) => (
                        <TableRow key={a.id}>
                            <TableCell>{a.member?.name}<div className="text-xs text-muted-foreground">{a.member?.member_no}</div></TableCell>
                            <TableCell>{a.event?.title}</TableCell>
                            <TableCell><StatusBadge status={a.status} /></TableCell>
                            <TableCell>{fmtDateTime(a.scanned_at)}</TableCell>
                            <TableCell className="text-muted-foreground">{a.review_reason ? (reasons[a.review_reason] ?? a.review_reason) : '—'}</TableCell>
                            <TableCell><Actions a={a} canAccept={tab === 'offline'} /></TableCell>
                        </TableRow>
                    ))}
                </DataTable>
            )}
            <Pager page={items} />
        </AppLayout>
    );
}
