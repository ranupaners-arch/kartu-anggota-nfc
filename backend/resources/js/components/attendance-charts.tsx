import { Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { DataTable } from '@/components/data-table';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { TableCell, TableRow } from '@/components/ui/table';
import { fmtDate, fmtTime } from '@/lib/format';

export type TrendPoint = { id: number; title: string; date: string; total: number; present: number; rate: number | null };
export type DivisionRate = { division: string; total: number; present: number; rate: number | null };
export type AtRisk = { member_id: number; member_no: string; name: string; division: string | null; alpa_streak: number; rate: number | null; total: number };

const pct = (v: number | null) => (v === null ? '–' : `${Number(v).toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`);

/** Lebar elemen sebenarnya (px), agar SVG digambar 1:1 dan teks sumbu tidak mengecil di HP. */
function useWidth(min = 240) {
    const box = useRef<HTMLDivElement>(null);
    const [w, setW] = useState(600);
    useEffect(() => {
        const el = box.current;
        if (!el) return;
        const ro = new ResizeObserver(([e]) => setW(Math.max(min, Math.round(e.contentRect.width))));
        ro.observe(el);
        return () => ro.disconnect();
    }, [min]);
    return [box, w] as const;
}

/**
 * Tren % hadir per kegiatan (satu seri → tanpa legenda; judul kartu menamainya).
 * Batang tipis berujung bulat 4px di atas garis dasar, grid samar 50/100%, tooltip saat diarahkan,
 * label nilai hanya pada kegiatan terakhir, tabel tersembunyi untuk pembaca layar.
 */
export function TrendChart({ points }: { points: TrendPoint[] }) {
    const [hover, setHover] = useState<number | null>(null);
    // Gambar sesuai lebar sebenarnya (bukan skala viewBox) agar teks sumbu tetap 11px di HP.
    const [box, W] = useWidth();
    if (points.length === 0) return <p className="text-sm text-muted-foreground">Belum ada kegiatan yang ditutup di periode ini.</p>;

    const H = 200, padL = 36, padB = 24, padT = 16;
    const plotW = W - padL, plotH = H - padB - padT;
    const step = plotW / points.length;
    const barW = Math.min(28, step * 0.6);
    const y = (v: number) => padT + plotH - (v / 100) * plotH;
    const active = hover !== null ? points[hover] : null;
    const last = points.length - 1;

    return (
        <div className="relative" ref={box}>
            <svg width={W} height={H} viewBox={`0 0 ${W} ${H}`} className="block max-w-full" role="img" aria-label="Grafik persentase hadir per kegiatan" onMouseLeave={() => setHover(null)}>
                {[0, 50, 100].map((g) => (
                    <g key={g}>
                        <line x1={padL} x2={W} y1={y(g)} y2={y(g)} className={g === 0 ? 'stroke-border' : 'stroke-border/60'} strokeDasharray={g === 0 ? undefined : '3 4'} />
                        <text x={padL - 6} y={y(g) + 4} textAnchor="end" className="fill-muted-foreground text-[11px]">{g}%</text>
                    </g>
                ))}
                {points.map((p, i) => {
                    const v = p.rate ?? 0;
                    const x = padL + i * step + (step - barW) / 2;
                    const top = y(v), h = Math.max(0, y(0) - top);
                    const r = Math.min(4, barW / 2, h);
                    return (
                        <g key={p.id} onMouseEnter={() => setHover(i)} onFocus={() => setHover(i)} tabIndex={0} aria-label={`${p.title}: ${pct(p.rate)}`}>
                            {/* area sentuh lebih besar dari batang */}
                            <rect x={padL + i * step} y={padT} width={step} height={plotH} fill="transparent" />
                            <path
                                d={`M${x},${y(0)} V${top + r} Q${x},${top} ${x + r},${top} H${x + barW - r} Q${x + barW},${top} ${x + barW},${top + r} V${y(0)} Z`}
                                className={hover === null || hover === i ? 'fill-[var(--chart-bar)]' : 'fill-[var(--chart-bar)] opacity-40'}
                            />
                            {i === last && hover === null && (
                                <text x={x + barW / 2} y={top - 6} textAnchor="middle" className="fill-foreground text-[11px] font-medium">{pct(p.rate)}</text>
                            )}
                        </g>
                    );
                })}
                <text x={padL} y={H - 6} className="fill-muted-foreground text-[11px]">{fmtDate(points[0].date)}</text>
                <text x={W} y={H - 6} textAnchor="end" className="fill-muted-foreground text-[11px]">{fmtDate(points[last].date)}</text>
            </svg>
            {active && (
                <div className="pointer-events-none absolute top-0 right-0 rounded-md border bg-popover px-3 py-2 text-xs shadow-md">
                    <div className="font-medium text-foreground">{active.title}</div>
                    <div className="text-muted-foreground">{fmtDate(active.date)}</div>
                    <div className="mt-1 tabular-nums text-foreground">{pct(active.rate)} hadir · {active.present}/{active.total}</div>
                </div>
            )}
            <table className="sr-only">
                <caption>Persentase hadir per kegiatan</caption>
                <thead><tr><th>Kegiatan</th><th>Tanggal</th><th>Hadir</th></tr></thead>
                <tbody>{points.map((p) => <tr key={p.id}><td>{p.title}</td><td>{fmtDate(p.date)}</td><td>{pct(p.rate)}</td></tr>)}</tbody>
            </table>
        </div>
    );
}

export type Arrivals = { start: string; bin_minutes: number; late_after: string; counts: number[] };

/**
 * Kedatangan per kelompok waktu sejak check-in dibuka (satu seri → tanpa legenda). Garis putus-putus
 * menandai batas telat: batang di kanannya berarti datang telat. Batang terakhir = kelompok berjalan.
 */
export function ArrivalChart({ data }: { data: Arrivals }) {
    const [box, W] = useWidth();
    const [hover, setHover] = useState<number | null>(null);
    const { counts, bin_minutes: bin } = data;
    const start = new Date(data.start).getTime();
    const binMs = bin * 60_000;
    const total = counts.reduce((a, b) => a + b, 0);

    const H = 180, padL = 28, padB = 24, padT = 18;
    const plotW = W - padL, plotH = H - padB - padT;
    const step = plotW / counts.length;
    const barW = Math.max(2, Math.min(28, step - 2)); // celah ≥ 2px antarbatang
    const max = Math.max(1, ...counts);
    const y = (v: number) => padT + plotH - (v / max) * plotH;
    const lateX = padL + ((new Date(data.late_after).getTime() - start) / binMs) * step;
    const showLate = lateX > padL && lateX < W;
    const range = (i: number) => `${fmtTime(new Date(start + i * binMs).toISOString())}–${fmtTime(new Date(start + (i + 1) * binMs).toISOString())}`;
    const active = hover !== null ? hover : null;

    return (
        <div className="relative" ref={box}>
            <svg width={W} height={H} viewBox={`0 0 ${W} ${H}`} className="block max-w-full" role="img" aria-label={`Grafik kedatangan per ${bin} menit, total ${total} orang`} onMouseLeave={() => setHover(null)}>
                {[0, max].map((g) => (
                    <g key={g}>
                        <line x1={padL} x2={W} y1={y(g)} y2={y(g)} className={g === 0 ? 'stroke-border' : 'stroke-border/60'} strokeDasharray={g === 0 ? undefined : '3 4'} />
                        <text x={padL - 6} y={y(g) + 4} textAnchor="end" className="fill-muted-foreground text-[11px] tabular-nums">{g}</text>
                    </g>
                ))}
                {counts.map((v, i) => {
                    const x = padL + i * step + (step - barW) / 2;
                    const top = y(v), h = Math.max(0, y(0) - top);
                    const r = Math.min(4, barW / 2, h);
                    return (
                        <g key={i} onMouseEnter={() => setHover(i)} onFocus={() => setHover(i)} tabIndex={0} aria-label={`${range(i)}: ${v} orang`}>
                            <rect x={padL + i * step} y={padT} width={step} height={plotH} fill="transparent" />
                            {h > 0 && (
                                <path
                                    d={`M${x},${y(0)} V${top + r} Q${x},${top} ${x + r},${top} H${x + barW - r} Q${x + barW},${top} ${x + barW},${top + r} V${y(0)} Z`}
                                    className={hover === null || hover === i ? 'fill-[var(--chart-bar)]' : 'fill-[var(--chart-bar)] opacity-40'}
                                />
                            )}
                        </g>
                    );
                })}
                {showLate && (
                    <g className="pointer-events-none">
                        <line x1={lateX} x2={lateX} y1={padT - 4} y2={y(0)} className="stroke-foreground/70" strokeDasharray="4 3" strokeWidth={1.5} />
                        {/* label pindah ke kiri garis bila dekat tepi kanan agar tidak terpotong */}
                        <text x={lateX > W - 110 ? lateX - 4 : lateX + 4} y={padT - 6} textAnchor={lateX > W - 110 ? 'end' : 'start'} className="fill-muted-foreground text-[11px]">Batas telat {fmtTime(data.late_after)}</text>
                    </g>
                )}
                <text x={padL} y={H - 6} className="fill-muted-foreground text-[11px] tabular-nums">{fmtTime(data.start)}</text>
                <text x={W} y={H - 6} textAnchor="end" className="fill-muted-foreground text-[11px] tabular-nums">{fmtTime(new Date(start + counts.length * binMs).toISOString())}</text>
            </svg>
            {active !== null && (
                <div className="pointer-events-none absolute top-0 right-0 rounded-md border bg-popover px-3 py-2 text-xs shadow-md">
                    <div className="font-medium tabular-nums text-foreground">{range(active)}</div>
                    <div className="tabular-nums text-muted-foreground">{counts[active]} orang datang</div>
                </div>
            )}
            <table className="sr-only">
                <caption>Kedatangan per {bin} menit</caption>
                <thead><tr><th>Waktu</th><th>Jumlah</th></tr></thead>
                <tbody>{counts.map((v, i) => <tr key={i}><td>{range(i)}</td><td>{v}</td></tr>)}</tbody>
            </table>
        </div>
    );
}

/** % hadir per divisi: batang horizontal satu warna, nilai sebagai teks (bukan warna). */
export function DivisionBars({ rows }: { rows: DivisionRate[] }) {
    if (rows.length === 0) return <p className="text-sm text-muted-foreground">Belum ada data divisi.</p>;
    return (
        <ul className="grid gap-3">
            {rows.map((r) => (
                <li key={r.division} className="grid gap-1">
                    <div className="flex justify-between text-sm"><span>{r.division}</span><span className="tabular-nums text-muted-foreground">{pct(r.rate)} · {r.present}/{r.total}</span></div>
                    <div className="h-2 rounded-full bg-muted" role="img" aria-label={`${r.division}: ${pct(r.rate)} hadir`}>
                        <div className="h-2 rounded-full bg-[var(--chart-bar)]" style={{ width: `${r.rate ?? 0}%` }} />
                    </div>
                </li>
            ))}
        </ul>
    );
}

export function StatsSection({ trend, divisions, atRisk, rules }: { trend: TrendPoint[]; divisions: DivisionRate[]; atRisk: AtRisk[]; rules: { streak: number; minRate: number; minRecords: number } }) {
    return (
        <>
            <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                <Card>
                    <CardHeader><CardTitle>Tren kehadiran</CardTitle><CardDescription>% hadir (termasuk telat) per kegiatan yang sudah ditutup.</CardDescription></CardHeader>
                    <CardContent><TrendChart points={trend} /></CardContent>
                </Card>
                <Card>
                    <CardHeader><CardTitle>Per divisi</CardTitle><CardDescription>Periode berjalan, terendah di atas.</CardDescription></CardHeader>
                    <CardContent><DivisionBars rows={divisions} /></CardContent>
                </Card>
            </div>
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2"><AlertTriangle className="size-4 text-amber-600 dark:text-amber-400" /> Perlu perhatian</CardTitle>
                    <CardDescription>Anggota aktif yang Alpa {rules.streak}× berturut-turut, atau kehadirannya di bawah {rules.minRate}% (minimal {rules.minRecords} catatan).</CardDescription>
                </CardHeader>
                <CardContent>
                    <DataTable head={['Anggota', 'Divisi', 'Alpa berturut-turut', 'Kehadiran']} count={atRisk.length} empty="Tidak ada — semua anggota aman.">
                        {atRisk.map((m) => (
                            <TableRow key={m.member_id}>
                                <TableCell><Link href={`/anggota/${m.member_id}`} className="font-medium hover:underline">{m.name}</Link><div className="text-xs text-muted-foreground">{m.member_no}</div></TableCell>
                                <TableCell>{m.division ?? '—'}</TableCell>
                                <TableCell className="tabular-nums">{m.alpa_streak >= rules.streak ? <span className="inline-flex items-center gap-1 font-medium text-amber-700 dark:text-amber-400"><AlertTriangle className="size-3.5" /> {m.alpa_streak}×</span> : m.alpa_streak}</TableCell>
                                <TableCell className="tabular-nums">{pct(m.rate)} <span className="text-xs text-muted-foreground">({m.total})</span></TableCell>
                            </TableRow>
                        ))}
                    </DataTable>
                </CardContent>
            </Card>
        </>
    );
}
