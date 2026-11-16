import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const tone: Record<string, string> = {
    green: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    amber: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    red: 'bg-destructive/10 text-destructive',
    gray: 'bg-muted text-muted-foreground',
    blue: 'bg-sky-500/10 text-sky-700 dark:text-sky-400',
};

const map: Record<string, [string, keyof typeof tone]> = {
    hadir: ['Hadir', 'green'], telat: ['Telat', 'amber'], izin: ['Izin', 'blue'], sakit: ['Sakit', 'blue'], alpa: ['Alpa', 'red'],
    belum_absen: ['Belum absen', 'gray'], void: ['Dibatalkan', 'gray'],
    active: ['Aktif', 'green'], inactive: ['Tidak aktif', 'gray'], applicant: ['Pendaftar', 'blue'], alumni: ['Alumni', 'gray'],
    blocked: ['Diblokir', 'red'], lost: ['Hilang', 'red'], unassigned: ['Belum dipakai', 'gray'],
    pending: ['Menunggu', 'amber'], approved: ['Disetujui', 'green'], rejected: ['Ditolak', 'red'],
    ready: ['Siap', 'green'], failed: ['Gagal', 'red'],
    open: ['Terbuka', 'green'], closed: ['Ditutup', 'gray'], disabled: ['Nonaktif', 'red'], revoked: ['Dicabut', 'red'],
};

export function StatusBadge({ status, label }: { status: string; label?: string }) {
    const [text, color] = map[status] ?? [status, 'gray'];
    return <Badge variant="secondary" className={cn('font-medium', tone[color])}>{label ?? text}</Badge>;
}
