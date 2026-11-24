import { Head, router } from '@inertiajs/react';
import { FileDown, TriangleAlert } from 'lucide-react';
import { StatusBadge } from '@/components/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import PortalLayout from '@/layouts/portal-layout';
import { fmtDate } from '@/lib/format';

type History = {
    term: string | null; total: number; attendance_rate: number | null;
    by_status: Record<'hadir' | 'telat' | 'izin' | 'sakit' | 'alpa', number>;
    warning: { at_risk: boolean; alpa_streak: number; rate: number | null } | null;
    recent: { event: string | null; date: string | null; status: string; source: string | null }[];
    fines?: { unpaid_total: number; items: { id: number; amount: number; status: string; event: string | null; date: string | null }[] };
    dues?: { unpaid_total: number; items: { id: number; title: string; amount: number; status: string; due_on: string | null }[] };
};

type Board = {
    opted_in: boolean; me: { points: number; rank: number; total: number } | null;
    top: { rank: number; name: string; division: string | null; points: number; is_me: boolean }[];
};

const statusLabel: Record<string, string> = { hadir: 'Hadir', telat: 'Telat', izin: 'Izin', sakit: 'Sakit', alpa: 'Alpa' };

function Leaderboard({ board }: { board: Board }) {
    if (!board.me) return null;
    return (
        <Card className="gap-3">
            <CardHeader>
                <CardTitle className="text-base">Papan peringkat</CardTitle>
                <CardDescription>{board.me.points > 0 ? <>Anda peringkat {board.me.rank} dari {board.me.total} anggota aktif ({board.me.points} poin) — hanya Anda yang melihat ini.</> : 'Anda belum punya poin periode ini.'}</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3">
                {board.top.length === 0 && <p className="text-sm text-muted-foreground">Belum ada nama yang tampil.</p>}
                {board.opted_in && board.me.points === 0 && <p className="text-sm text-muted-foreground">Nama Anda akan tampil setelah mendapat poin pertama.</p>}
                <ol className="divide-y">
                    {board.top.map((t, i) => (
                        <li key={i} className={`flex items-center gap-3 py-2 text-sm ${t.is_me ? 'font-semibold' : ''}`}>
                            <span className="w-6 text-right tabular-nums text-muted-foreground">{t.rank}</span>
                            <span className="min-w-0 flex-1 truncate">{t.name}{t.is_me && ' (Anda)'}<span className="block text-xs font-normal text-muted-foreground">{t.division}</span></span>
                            <span className="tabular-nums">{t.points} poin</span>
                        </li>
                    ))}
                </ol>
                <p className="text-xs text-muted-foreground">Hanya anggota yang memilih tampil yang terlihat: nama, divisi, dan poin. Bisa diubah kapan saja.</p>
                <Button variant="outline" onClick={() => router.put('/saya/peringkat', { opt_in: !board.opted_in }, { preserveScroll: true })}>
                    {board.opted_in ? 'Sembunyikan nama saya' : 'Tampilkan nama saya'}
                </Button>
            </CardContent>
        </Card>
    );
}

export default function PortalHistory({ history: h, leaderboard }: { history: History; leaderboard: Board }) {
    const pct = h.attendance_rate === null ? '–' : `${h.attendance_rate.toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`;
    return (
        <PortalLayout title="Riwayat">
            <Head title="Riwayat" />
            {h.warning?.at_risk && (
                <Alert variant="destructive">
                    <TriangleAlert />
                    <AlertTitle>Kehadiran Anda perlu perhatian</AlertTitle>
                    <AlertDescription>
                        {h.warning.alpa_streak >= 3 ? `Alpa ${h.warning.alpa_streak}× berturut-turut. ` : ''}
                        Kehadiran {h.warning.rate ?? 0}% periode ini. Ajukan izin bila berhalangan, atau hubungi pengurus.
                    </AlertDescription>
                </Alert>
            )}
            <Card>
                <CardHeader>
                    <CardDescription>Kehadiran {h.term ? `periode ${h.term}` : ''}</CardDescription>
                    <CardTitle className="text-4xl tabular-nums">{pct}</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4">
                    <dl className="grid grid-cols-5 gap-1 text-center">
                        {(Object.keys(statusLabel) as (keyof History['by_status'])[]).map((k) => (
                            <div key={k} className="rounded-lg bg-muted/60 px-1 py-2">
                                <dt className="text-xs text-muted-foreground">{statusLabel[k]}</dt>
                                <dd className="text-lg font-semibold tabular-nums">{h.by_status[k]}</dd>
                            </div>
                        ))}
                    </dl>
                    <Button variant="outline" render={<a href="/saya/rapor" />}><FileDown /> Unduh rapor PDF</Button>
                </CardContent>
            </Card>
            {h.dues && h.dues.items.length > 0 && (
                <Card className="gap-2">
                    <CardHeader>
                        <CardTitle className="text-base">Iuran</CardTitle>
                        <CardDescription>Belum lunas: <b className="text-foreground">Rp{h.dues.unpaid_total.toLocaleString('id-ID')}</b> — bayar ke bendahara.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul className="divide-y">
                            {h.dues.items.map((d) => (
                                <li key={d.id} className="flex items-center gap-3 py-2 text-sm">
                                    <span className="min-w-0 flex-1 truncate">{d.title}{d.due_on && <span className="block text-xs text-muted-foreground">jatuh tempo {fmtDate(d.due_on)}</span>}</span>
                                    <span className="tabular-nums">Rp{d.amount.toLocaleString('id-ID')}</span>
                                    <StatusBadge status={d.status === 'unpaid' ? 'pending' : d.status === 'paid' ? 'approved' : 'closed'} label={{ unpaid: 'Belum lunas', paid: 'Lunas', waived: 'Dibebaskan' }[d.status] ?? d.status} />
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            )}
            {h.fines && h.fines.items.length > 0 && (
                <Card className="gap-2">
                    <CardHeader>
                        <CardTitle className="text-base">Denda absen pakai QR</CardTitle>
                        <CardDescription>Belum lunas: <b className="text-foreground">Rp{h.fines.unpaid_total.toLocaleString('id-ID')}</b> — bayar ke pengurus. Bawa kartu agar tidak didenda.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul className="divide-y">
                            {h.fines.items.map((f) => (
                                <li key={f.id} className="flex items-center gap-3 py-2 text-sm">
                                    <span className="min-w-0 flex-1 truncate">{f.event ?? '—'}<span className="block text-xs text-muted-foreground">{fmtDate(f.date)}</span></span>
                                    <span className="tabular-nums">Rp{f.amount.toLocaleString('id-ID')}</span>
                                    <StatusBadge status={f.status === 'unpaid' ? 'pending' : f.status === 'paid' ? 'approved' : 'closed'} label={{ unpaid: 'Belum lunas', paid: 'Lunas', waived: 'Dibebaskan' }[f.status] ?? f.status} />
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            )}
            <Leaderboard board={leaderboard} />
            <Card className="gap-2">
                <CardHeader><CardTitle className="text-base">Riwayat terbaru</CardTitle></CardHeader>
                <CardContent>
                    {h.recent.length === 0 && <p className="py-4 text-center text-sm text-muted-foreground">Belum ada catatan kehadiran.</p>}
                    <ul className="divide-y">
                        {h.recent.map((r, i) => (
                            <li key={i} className="flex items-center gap-3 py-2.5">
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-sm font-medium">{r.event ?? '—'}</div>
                                    <div className="text-xs text-muted-foreground">{fmtDate(r.date)}{r.source === 'qr' ? ' · QR' : r.source === 'nfc' ? ' · Kartu' : ''}</div>
                                </div>
                                <StatusBadge status={r.status} label={statusLabel[r.status] ?? r.status} />
                            </li>
                        ))}
                    </ul>
                </CardContent>
            </Card>
        </PortalLayout>
    );
}
