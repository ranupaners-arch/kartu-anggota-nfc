import { Head, Link } from '@inertiajs/react';
import { CalendarDays, ChevronRight, MapPin } from 'lucide-react';
import { Copyright } from '@/components/copyright';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card } from '@/components/ui/card';
import ScannerLayout from '@/layouts/scanner-layout';
import { fmtTime } from '@/lib/format';

type Ev = { id: number; title: string; location: string | null; starts_at: string; checkin_opens_at: string; checkin_closes_at: string; open: boolean };

export default function ScannerIndex({ events, isAdmin }: { events: Ev[]; isAdmin: boolean }) {
    return (
        <ScannerLayout title="Pindai QR" subtitle="Pilih kegiatan" back={isAdmin ? '/' : undefined}>
            <Head title="Pindai QR" />
            <Alert>
                <AlertDescription>Untuk memindai <b>QR Saya</b> anggota dengan kamera. Kartu NFC tetap dipindai lewat app Android.</AlertDescription>
            </Alert>
            {events.length === 0 && <Card className="p-6 text-center text-sm text-muted-foreground">Tidak ada kegiatan yang membuka check-in hari ini.</Card>}
            <ul className="grid gap-2">
                {events.map((e) => (
                    <li key={e.id}>
                        <Link href={`/pindai/${e.id}`} className="flex items-center gap-3 rounded-xl border bg-card p-4 hover:bg-muted/50">
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-medium">{e.title}</span>
                                <span className="mt-1 flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                                    <span className="inline-flex items-center gap-1"><CalendarDays className="size-3.5" />check-in {fmtTime(e.checkin_opens_at)}-{fmtTime(e.checkin_closes_at)}</span>
                                    {e.location && <span className="inline-flex items-center gap-1"><MapPin className="size-3.5" />{e.location}</span>}
                                </span>
                            </span>
                            <span className={`shrink-0 rounded-md px-2 py-0.5 text-xs font-medium ${e.open ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-muted text-muted-foreground'}`}>{e.open ? 'Dibuka' : 'Segera'}</span>
                            <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                        </Link>
                    </li>
                ))}
            </ul>
            <Copyright className="text-center" />
        </ScannerLayout>
    );
}
