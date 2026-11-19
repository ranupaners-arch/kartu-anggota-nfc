import { Link } from '@inertiajs/react';
import { AlertTriangle, ChevronLeft, ChevronRight } from 'lucide-react';
import { useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { fmtTime } from '@/lib/format';
import { cn } from '@/lib/utils';

export type CalEvent = {
    id: number; title: string; type: string | null; location: string | null; division_id: number | null;
    is_mandatory: boolean; starts_at: string; ends_at: string; closed_at: string | null;
};
export type Calendar = { month: string; from: string; to: string; events: CalEvent[] };
type Division = { id: number; name: string };

const TZ = 'Asia/Jakarta';
const dayFmt = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' });
/** Tanggal (YYYY-MM-DD) di zona Asia/Jakarta. */
const dayKey = (d: Date | string) => dayFmt.format(new Date(d));
const WEEKDAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

/** Geser bulan "YYYY-MM" sebanyak n. */
function shiftMonth(month: string, n: number) {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(Date.UTC(y, m - 1 + n, 1));
    return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** Daftar tanggal dari..sampai (inklusif), sebagai "YYYY-MM-DD". */
function daysBetween(from: string, to: string) {
    const out: string[] = [];
    for (let d = new Date(`${from}T00:00:00Z`); d <= new Date(`${to}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + 1)) out.push(d.toISOString().slice(0, 10));
    return out;
}

/** Warna divisi: urutan tetap (id) → slot 1–8; lebih dari 8 divisi atau kegiatan umum → abu. */
function colorOf(divisionId: number | null, order: Map<number, number>) {
    const i = divisionId ? order.get(divisionId) : undefined;
    return i !== undefined && i < 8 ? `var(--cat-${i + 1})` : 'var(--cat-none)';
}

/** Kegiatan yang waktunya tumpang-tindih dengan kegiatan lain di hari yang sama. */
function clashes(events: CalEvent[]) {
    const ids = new Set<number>();
    events.forEach((a, i) => events.slice(i + 1).forEach((b) => {
        if (new Date(a.starts_at) < new Date(b.ends_at) && new Date(b.starts_at) < new Date(a.ends_at)) {
            ids.add(a.id);
            ids.add(b.id);
        }
    }));
    return ids;
}

function Chip({ e, color, clash }: { e: CalEvent; color: string; clash: boolean }) {
    return (
        <Link href={`/kegiatan/${e.id}`} title={[e.title, e.location].filter(Boolean).join(' · ')}
            className={cn('grid min-w-0 rounded-sm border-l-[3px] bg-muted/60 px-1.5 py-0.5 text-xs leading-snug hover:bg-muted', e.closed_at && 'opacity-70')}
            style={{ borderLeftColor: color }}>
            <span className="flex items-center gap-1 tabular-nums text-muted-foreground">
                {fmtTime(e.starts_at)}
                {clash && <AlertTriangle className="size-3 shrink-0 text-amber-600 dark:text-amber-400" aria-label="Bentrok jadwal" />}
            </span>
            <span className="line-clamp-2 font-medium break-words">{e.title}</span>
        </Link>
    );
}

export function EventCalendar({ calendar, divisions, query }: { calendar: Calendar; divisions: Division[]; query: Record<string, string> }) {
    const order = useMemo(() => new Map(divisions.map((d, i) => [d.id, i])), [divisions]);
    const nameOf = useMemo(() => new Map(divisions.map((d) => [d.id, d.name])), [divisions]);
    const days = useMemo(() => daysBetween(calendar.from, calendar.to), [calendar.from, calendar.to]);
    const byDay = useMemo(() => {
        const map = new Map<string, CalEvent[]>();
        for (const e of calendar.events) {
            // Kegiatan lebih dari sehari tampil di tiap hari yang dilaluinya.
            for (const d of daysBetween(dayKey(e.starts_at), dayKey(e.ends_at))) {
                if (!map.has(d)) map.set(d, []);
                map.get(d)!.push(e);
            }
        }
        return map;
    }, [calendar.events]);
    const clashIds = useMemo(() => {
        const ids = new Set<number>();
        byDay.forEach((list) => clashes(list).forEach((id) => ids.add(id)));
        return ids;
    }, [byDay]);

    const today = dayKey(new Date());
    const [y, m] = calendar.month.split('-').map(Number);
    const title = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, 1)));
    const href = (month: string) => `/kegiatan?${new URLSearchParams({ ...query, tab: 'kalender', bulan: month })}`;
    const used = [...new Set(calendar.events.map((e) => e.division_id))];
    const agenda = days.filter((d) => d.startsWith(calendar.month) && byDay.has(d));
    const dayLabel = (d: string) => new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(`${d}T00:00:00Z`));

    return (
        <div className="@container grid min-w-0 grid-cols-[minmax(0,1fr)] gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="mr-auto text-lg font-semibold capitalize">{title}</h2>
                <Button variant="outline" size="sm" render={<Link href={href(today.slice(0, 7))} preserveScroll />}>Bulan ini</Button>
                <Button variant="outline" size="icon-sm" aria-label="Bulan sebelumnya" render={<Link href={href(shiftMonth(calendar.month, -1))} preserveScroll />}><ChevronLeft /></Button>
                <Button variant="outline" size="icon-sm" aria-label="Bulan berikutnya" render={<Link href={href(shiftMonth(calendar.month, 1))} preserveScroll />}><ChevronRight /></Button>
            </div>

            {used.length > 0 && (
                <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground" aria-label="Keterangan warna">
                    {used.sort((a, b) => (order.get(a ?? -1) ?? 99) - (order.get(b ?? -1) ?? 99)).map((id) => (
                        <li key={id ?? 'umum'} className="flex items-center gap-1.5">
                            <span className="size-2.5 rounded-sm" style={{ background: colorOf(id, order) }} />{id ? nameOf.get(id) : 'Kegiatan umum'}
                        </li>
                    ))}
                    {clashIds.size > 0 && <li className="flex items-center gap-1.5"><AlertTriangle className="size-3 text-amber-600 dark:text-amber-400" />Bentrok jadwal</li>}
                </ul>
            )}

            {/* Layar lebar: kotak bulanan. */}
            <Card className="hidden gap-0 overflow-hidden py-0 @2xl:grid">
                <div className="grid grid-cols-7 border-b bg-muted/40 text-xs font-medium text-muted-foreground" aria-hidden="true">
                    {WEEKDAYS.map((w) => <div key={w} className="px-2 py-1.5">{w}</div>)}
                </div>
                <ol className="grid grid-cols-7">
                    {days.map((d) => {
                        const list = byDay.get(d) ?? [];
                        const outside = !d.startsWith(calendar.month);
                        return (
                            <li key={d} className={cn('flex min-h-28 min-w-0 flex-col gap-1 border-r border-b p-1.5 [&:nth-child(7n)]:border-r-0', outside && 'bg-muted/30')}
                                aria-label={`${dayLabel(d)}, ${list.length ? `${list.length} kegiatan` : 'tidak ada kegiatan'}`}>
                                <span className={cn('flex size-6 items-center justify-center rounded-full text-xs tabular-nums', outside && 'text-muted-foreground', d === today && 'bg-primary font-semibold text-primary-foreground')}>
                                    {Number(d.slice(8))}
                                </span>
                                {list.map((e) => <Chip key={e.id} e={e} color={colorOf(e.division_id, order)} clash={clashIds.has(e.id)} />)}
                            </li>
                        );
                    })}
                </ol>
            </Card>

            {/* Layar sempit: daftar agenda per hari (bulan ini saja). */}
            <div className="grid grid-cols-[minmax(0,1fr)] gap-3 @2xl:hidden">
                {agenda.length === 0 && <Card className="p-6 text-center text-sm text-muted-foreground">Tidak ada kegiatan bulan ini.</Card>}
                {agenda.map((d) => (
                    <section key={d} className="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-1.5">
                        <h3 className={cn('text-sm font-medium', d === today && 'text-primary')}>{dayLabel(d)}{d === today && ' · hari ini'}</h3>
                        {(byDay.get(d) ?? []).map((e) => (
                            <Link key={e.id} href={`/kegiatan/${e.id}`} className="flex items-center gap-3 rounded-lg border border-l-4 bg-card p-3 hover:bg-muted/50" style={{ borderLeftColor: colorOf(e.division_id, order) }}>
                                <span className="w-12 shrink-0 text-sm tabular-nums text-muted-foreground">{fmtTime(e.starts_at)}</span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium">{e.title}</span>
                                    <span className="block truncate text-xs text-muted-foreground">{[e.division_id ? nameOf.get(e.division_id) : 'Kegiatan umum', e.location].filter(Boolean).join(' · ')}</span>
                                </span>
                                {clashIds.has(e.id) && <span className="flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400"><AlertTriangle className="size-3.5" />Bentrok</span>}
                            </Link>
                        ))}
                    </section>
                ))}
            </div>
        </div>
    );
}
