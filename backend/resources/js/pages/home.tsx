import { Head, Link } from '@inertiajs/react';
import { CalendarDays, ClipboardCheck, ScanLine, ShieldAlert, Users } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDateTime, fmtTime } from '@/lib/format';

type EventRow = { id: number; title: string; location: string | null; starts_at: string; checkin_closes_at: string; present_count?: number };

interface Props {
    term: { name: string } | null;
    activeMembers: number;
    ongoing: EventRow[];
    upcoming: EventRow[];
    pendingExcuses: number;
    needsReview: number;
    scans7d: number;
}

function Stat({ label, value, icon: Icon, href }: { label: string; value: number; icon: typeof Users; href?: string }) {
    const body = (
        <Card className="transition-colors hover:bg-muted/40">
            <CardHeader className="flex flex-row items-center justify-between gap-2 pb-2">
                <CardDescription>{label}</CardDescription>
                <Icon className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent><div className="text-2xl font-semibold tabular-nums">{value}</div></CardContent>
        </Card>
    );
    return href ? <Link href={href}>{body}</Link> : body;
}

export default function Home({ term, activeMembers, ongoing, upcoming, pendingExcuses, needsReview, scans7d }: Props) {
    return (
        <AppLayout title="Beranda">
            <Head title="Beranda" />
            <p className="text-sm text-muted-foreground">Periode: {term?.name ?? 'belum ada periode aktif'}</p>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <Stat label="Anggota aktif" value={activeMembers} icon={Users} />
                <Stat label="Kegiatan berlangsung" value={ongoing.length} icon={CalendarDays} />
                <Stat label="Absensi 7 hari" value={scans7d} icon={ScanLine} />
                <Stat label="Izin menunggu" value={pendingExcuses} icon={ClipboardCheck} href="/izin" />
                <Stat label="Perlu ditinjau" value={needsReview} icon={ShieldAlert} href="/tinjauan" />
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle>Sedang berlangsung</CardTitle></CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader><TableRow><TableHead>Kegiatan</TableHead><TableHead>Check-in ditutup</TableHead><TableHead className="text-right">Hadir</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {ongoing.length === 0 && <TableRow><TableCell colSpan={3} className="text-muted-foreground">Tidak ada.</TableCell></TableRow>}
                                {ongoing.map((e) => (
                                    <TableRow key={e.id}>
                                        <TableCell><Link href={`/kegiatan/${e.id}`} className="font-medium hover:underline">{e.title}</Link></TableCell>
                                        <TableCell>{fmtTime(e.checkin_closes_at)}</TableCell>
                                        <TableCell className="text-right"><Badge variant="secondary">{e.present_count ?? 0}</Badge></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader><CardTitle>Akan datang</CardTitle></CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader><TableRow><TableHead>Kegiatan</TableHead><TableHead>Mulai</TableHead><TableHead>Lokasi</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {upcoming.length === 0 && <TableRow><TableCell colSpan={3} className="text-muted-foreground">Tidak ada.</TableCell></TableRow>}
                                {upcoming.map((e) => (
                                    <TableRow key={e.id}>
                                        <TableCell><Link href={`/kegiatan/${e.id}`} className="font-medium hover:underline">{e.title}</Link></TableCell>
                                        <TableCell>{fmtDateTime(e.starts_at)}</TableCell>
                                        <TableCell>{e.location ?? '—'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
