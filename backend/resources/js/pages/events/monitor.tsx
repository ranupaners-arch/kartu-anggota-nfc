import { Head, Link, usePoll } from '@inertiajs/react';
import { ArrowLeft, Maximize, Minimize, Search, UserRound } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { fmtTime } from '@/lib/format';
import { cn } from '@/lib/utils';

type Ev = { id: number; title: string; location: string | null; checkin_opens_at: string; late_after: string; checkin_closes_at: string; closed_at: string | null };
type Stats = { target: number; present: number; hadir: number; telat: number; excused: number; alpa: number; missing: number; rate: number | null };
type Scan = {
    id: number; status: string; source: string; scanned_at: string; needs_review: boolean;
    member: { name: string | null; member_no: string | null; division: string | null; photo_url: string | null };
};
type Missing = { total: number; people: { member_id: number; member_no: string; name: string; division: string | null }[] };

const POLL_MS = 4000;

/** Jam lokal yang disetel ke jam server (agar hitung mundur benar walau jam laptop meleset). */
function useServerNow(serverTime: string) {
    const [offset, setOffset] = useState(() => new Date(serverTime).getTime() - Date.now());
    const [now, setNow] = useState(() => Date.now() + offset);
    useEffect(() => setOffset(new Date(serverTime).getTime() - Date.now()), [serverTime]);
    useEffect(() => {
        const t = setInterval(() => setNow(Date.now() + offset), 1000);
        return () => clearInterval(t);
    }, [offset]);
    return now;
}

function countdown(ms: number) {
    const s = Math.max(0, Math.floor(ms / 1000));
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return h > 0 ? `${h} j ${m} m` : `${m}:${String(sec).padStart(2, '0')}`;
}

function windowState(ev: Ev, now: number): { label: string; detail: string; tone: string } {
    const opens = new Date(ev.checkin_opens_at).getTime(), late = new Date(ev.late_after).getTime(), closes = new Date(ev.checkin_closes_at).getTime();
    if (ev.closed_at) return { label: 'Kegiatan ditutup', detail: `ditutup ${fmtTime(ev.closed_at)}`, tone: 'bg-muted text-muted-foreground' };
    if (now < opens) return { label: 'Check-in belum dibuka', detail: `dibuka dalam ${countdown(opens - now)}`, tone: 'bg-sky-500/10 text-sky-700 dark:text-sky-400' };
    if (now <= late) return { label: 'Tepat waktu', detail: `batas telat ${fmtTime(ev.late_after)} · sisa ${countdown(late - now)}`, tone: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' };
    if (now <= closes) return { label: 'Terlambat', detail: `check-in ditutup ${fmtTime(ev.checkin_closes_at)} · sisa ${countdown(closes - now)}`, tone: 'bg-amber-500/10 text-amber-700 dark:text-amber-400' };
    return { label: 'Check-in ditutup', detail: `sejak ${fmtTime(ev.checkin_closes_at)}`, tone: 'bg-muted text-muted-foreground' };
}

function Photo({ url, name, size = 'size-12' }: { url: string | null; name: string | null; size?: string }) {
    const [failed, setFailed] = useState(false);
    if (!url || failed) {
        return <div className={cn(size, 'flex shrink-0 items-center justify-center rounded-lg bg-muted')}><UserRound className="size-1/2 text-muted-foreground" /></div>;
    }
    return <img src={url} alt={name ? `Foto ${name}` : 'Foto anggota'} onError={() => setFailed(true)} className={cn(size, 'shrink-0 rounded-lg object-cover')} />;
}

function Stat({ label, value, sub, className }: { label: string; value: React.ReactNode; sub?: string; className?: string }) {
    return (
        <Card className={className}>
            <CardHeader><CardDescription className="text-base">{label}</CardDescription><CardTitle className="text-5xl font-semibold tabular-nums">{value}</CardTitle></CardHeader>
            {sub && <CardContent className="text-sm text-muted-foreground">{sub}</CardContent>}
        </Card>
    );
}

export default function EventMonitor({ event, stats, latest, missing, serverTime }: { event: Ev; stats: Stats; latest: Scan[]; missing: Missing; serverTime: string }) {
    usePoll(POLL_MS, { only: ['stats', 'latest', 'missing', 'serverTime'] }, { keepAlive: true });
    const now = useServerNow(serverTime);
    const state = windowState(event, now);
    const [query, setQuery] = useState('');
    const [fullscreen, setFullscreen] = useState(false);

    // Sorot scan yang baru masuk sejak pembaruan sebelumnya.
    const seen = useRef<Set<number> | null>(null);
    const [fresh, setFresh] = useState<Set<number>>(new Set());
    useEffect(() => {
        const ids = latest.map((s) => s.id);
        if (seen.current) {
            const added = ids.filter((id) => !seen.current!.has(id));
            if (added.length) {
                setFresh(new Set(added));
                const t = setTimeout(() => setFresh(new Set()), 3000);
                ids.forEach((id) => seen.current!.add(id));
                return () => clearTimeout(t);
            }
        } else {
            seen.current = new Set(ids);
        }
    }, [latest]);

    useEffect(() => {
        const onChange = () => setFullscreen(Boolean(document.fullscreenElement));
        document.addEventListener('fullscreenchange', onChange);
        return () => document.removeEventListener('fullscreenchange', onChange);
    }, []);
    const toggleFullscreen = () => (document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen());

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        return q ? missing.people.filter((p) => p.name.toLowerCase().includes(q) || p.member_no.toLowerCase().includes(q)) : missing.people;
    }, [missing.people, query]);
    const pct = stats.target ? Math.min(100, (stats.present / stats.target) * 100) : 0;

    return (
        <div className="flex min-h-screen flex-col gap-4 bg-background p-4 md:p-6">
            <Head title={`Monitor · ${event.title}`} />
            <header className="flex flex-wrap items-center gap-3">
                <Button variant="ghost" size="icon" aria-label="Kembali ke kegiatan" render={<Link href={`/kegiatan/${event.id}`} />}><ArrowLeft /></Button>
                <div className="min-w-0 flex-1">
                    <h1 className="truncate text-2xl font-semibold md:text-3xl">{event.title}</h1>
                    <p className="text-sm text-muted-foreground">{event.location ?? 'Tanpa lokasi'}</p>
                </div>
                <div className={cn('rounded-lg px-3 py-2 text-right', state.tone)}>
                    <div className="font-semibold">{state.label}</div>
                    <div className="text-xs tabular-nums">{state.detail}</div>
                </div>
                <div className="text-right">
                    <div className="text-3xl font-semibold tabular-nums">{new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'Asia/Jakarta' }).format(now)}</div>
                    <div className="flex items-center justify-end gap-1.5 text-xs text-muted-foreground">
                        <span className="relative flex size-2"><span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500 opacity-75" /><span className="relative inline-flex size-2 rounded-full bg-emerald-500" /></span>
                        Langsung · tiap {POLL_MS / 1000} detik
                    </div>
                </div>
                <Button variant="outline" size="icon" aria-label={fullscreen ? 'Keluar layar penuh' : 'Layar penuh'} onClick={toggleFullscreen}>{fullscreen ? <Minimize /> : <Maximize />}</Button>
            </header>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card className="sm:col-span-2 lg:col-span-1">
                    <CardHeader>
                        <CardDescription className="text-base">Hadir</CardDescription>
                        <CardTitle className="text-5xl font-semibold tabular-nums">{stats.present}<span className="text-2xl text-muted-foreground"> / {stats.target}</span></CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2">
                        <div className="h-3 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100}>
                            <div className="h-full rounded-full bg-emerald-500 transition-all duration-700" style={{ width: `${pct}%` }} />
                        </div>
                        <div className="text-sm text-muted-foreground">{stats.rate ?? 0}% · tepat waktu {stats.hadir}</div>
                    </CardContent>
                </Card>
                <Stat label="Telat" value={stats.telat} />
                <Stat label="Izin / Sakit" value={stats.excused} />
                <Stat label="Belum datang" value={stats.missing} sub={stats.alpa ? `${stats.alpa} sudah ditandai alpa` : undefined} />
            </div>

            <div className="grid min-h-0 flex-1 gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader><CardTitle>Scan terbaru</CardTitle></CardHeader>
                    <CardContent className="grid gap-2">
                        {latest.length === 0 && <p className="py-8 text-center text-muted-foreground">Belum ada yang scan.</p>}
                        {latest.map((s) => (
                            <div key={s.id} className={cn('flex items-center gap-3 rounded-lg border p-2 transition-colors duration-1000', fresh.has(s.id) ? 'border-emerald-500/50 bg-emerald-500/10' : 'border-transparent')}>
                                <Photo url={s.member.photo_url} name={s.member.name} />
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-lg font-medium">{s.member.name}</div>
                                    <div className="truncate text-sm text-muted-foreground">{[s.member.member_no, s.member.division].filter(Boolean).join(' · ')}</div>
                                </div>
                                <div className="flex flex-col items-end gap-1">
                                    <div className="flex gap-1"><StatusBadge status={s.status} />{s.needs_review && <StatusBadge status="pending" label="Ditinjau" />}</div>
                                    <div className="text-xs tabular-nums text-muted-foreground">{fmtTime(s.scanned_at)} · {s.source === 'qr' ? 'QR' : s.source === 'nfc' ? 'Kartu' : s.source}</div>
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>
                <Card className="flex min-h-0 flex-col">
                    <CardHeader>
                        <CardTitle>Belum datang ({missing.total})</CardTitle>
                        <div className="relative">
                            <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input className="pl-8" placeholder="Cari nama / nomor" value={query} onChange={(e) => setQuery(e.target.value)} />
                        </div>
                    </CardHeader>
                    <CardContent className="min-h-0 flex-1 overflow-y-auto lg:max-h-[50vh]">
                        {filtered.length === 0 && <p className="py-6 text-center text-muted-foreground">{missing.total === 0 ? 'Semua peserta sudah tercatat.' : 'Tidak ada yang cocok.'}</p>}
                        <ul className="divide-y">
                            {filtered.map((p) => (
                                <li key={p.member_id} className="flex items-center justify-between gap-2 py-2">
                                    <span className="truncate">{p.name}</span>
                                    <span className="shrink-0 text-xs text-muted-foreground">{[p.member_no, p.division].filter(Boolean).join(' · ')}</span>
                                </li>
                            ))}
                        </ul>
                        {missing.total > missing.people.length && <p className="pt-2 text-xs text-muted-foreground">Menampilkan {missing.people.length} dari {missing.total}.</p>}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
