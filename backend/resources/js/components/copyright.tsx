import { cn } from '@/lib/utils';

/** Pemberitahuan hak cipta & lisensi (GPL-3.0-or-later) — wajib dipertahankan pada versi turunan. */
export function Copyright({ className }: { className?: string }) {
    return (
        <p className={cn('text-xs text-muted-foreground', className)}>
            © 2026 Ranupane Rimba ·{' '}
            <a href="https://github.com/ranupaners-arch/kartu-anggota-nfc" target="_blank" rel="noreferrer" className="underline-offset-4 hover:underline">
                Kartu Anggota NFC
            </a>{' '}
            · GPL-3.0
        </p>
    );
}
