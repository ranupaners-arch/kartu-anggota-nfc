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
type Held = { id: number; scanned_at: string; member: Att['member']; event: Att['event']; access_ended_at: string | null; device: { name: string } | null; user: { name: string } | null };
type Log = { id: number; created_at: string; entity_id: number; payload: { event_id?: number; device_id?: number } | null };

const reasons: Record<string, string> = { card_blocked_after_scan: 'Kartu diblokir setelah scan offline' };

function Actions({ path, canAccept }: { path: string; canAccept: boolean }) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState('');
    const send = (action: 'terima' | 'batalkan') => router.post(`${path}/${action}`, { reason }, { preserveScroll: true, onError: (e) => setError(e.reason ?? '') });
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <Input className="h-7 w-32" placeholder="Alasan" value={reason} onChange={(e) => setReason(e.target.value)} />
            {canAccept && <Button size="sm" disabled={!reason} onClick={() => send('terima')}>Terima</Button>}
            <Button size="sm" variant="destructive" disabled={!reason} onClick={() => send('batalkan')}>Batalkan</Button>
            {error && <p className="w-full text-xs text-destructive">{error}</p>}
        </div>
    );
}

export default function Reviews({ tab, items }: { tab: string; items: Paginated<Att | Log | Held> }) {
    const help: Record<string, string> = {
        offline: 'Scan offline yang bermasalah saat disinkron.',
        tertahan: 'Scan offline dari petugas yang aksesnya berakhir (perangkat dicabut, peran diubah, atau akun dinonaktifkan). Belum tercatat.',
        override: 'Scan di luar jendela check-in yang di-override petugas dengan alasan.',
        anomali: 'Kartu yang sama di-tap di kegiatan/perangkat berbeda dalam < 60 detik. Periksa kemungkinan kartu digandakan.',
    };
    return (
        <AppLayout title="Perlu Ditinjau">
            <Head title="Perlu Ditinjau" />
            <PageTabs tabs={{ offline: 'Konflik Offline', tertahan: 'Akses Berakhir', override: 'Override', anomali: 'Anomali' }} current={tab} />
            <p className="text-sm text-muted-foreground">{help[tab]}</p>
            {tab === 'tertahan' ? (
                <DataTable head={['Anggota', 'Kegiatan', 'Perangkat', 'Waktu scan', 'Tindakan']} count={items.data.length} empty="Tidak ada scan tertahan.">
                    {(items.data as Held[]).map((h) => (
                        <TableRow key={h.id}>
                            <TableCell>{h.member?.name ?? '-'}<div className="text-xs text-muted-foreground">{h.member?.member_no}</div></TableCell>
                            <TableCell>{h.event?.title}</TableCell>
                            <TableCell>{h.device?.name}<div className="text-xs text-muted-foreground">{h.user?.name}</div></TableCell>
                            <TableCell>
                                {fmtDateTime(h.scanned_at)}
                                {h.access_ended_at && new Date(h.scanned_at) > new Date(h.access_ended_at) && <div className="text-xs text-destructive">Setelah akses berakhir</div>}
                            </TableCell>
                            <TableCell><Actions path={`/tinjauan/tertahan/${h.id}`} canAccept /></TableCell>
                        </TableRow>
                    ))}
                </DataTable>
            ) : tab === 'anomali' ? (
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
                            <TableCell className="text-muted-foreground">{a.review_reason ? (reasons[a.review_reason] ?? a.review_reason) : '-'}</TableCell>
                            <TableCell><Actions path={`/tinjauan/${a.id}`} canAccept={tab === 'offline'} /></TableCell>
                        </TableRow>
                    ))}
                </DataTable>
            )}
            <Pager page={items} />
        </AppLayout>
    );
}
