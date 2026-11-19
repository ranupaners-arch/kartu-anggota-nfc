import { Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, History, LogOut, QrCode, UserRound, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';
import { FlashToaster } from '@/layouts/app-layout';

const tabs: { href: string; label: string; icon: LucideIcon }[] = [
    { href: '/saya', label: 'QR Saya', icon: QrCode },
    { href: '/saya/kegiatan', label: 'Kegiatan', icon: CalendarDays },
    { href: '/saya/riwayat', label: 'Riwayat', icon: History },
    { href: '/saya/akun', label: 'Akun', icon: UserRound },
];

/** Kerangka portal anggota: dirancang untuk HP — judul di atas, isi di tengah, navigasi di bawah. */
export default function PortalLayout({ title, children }: { title: string; children: ReactNode }) {
    const { url, props } = usePage<SharedProps>();
    const path = url.split('?')[0];
    return (
        <div className="flex min-h-dvh flex-col bg-background">
            <header className="sticky top-0 z-10 border-b bg-background/95 backdrop-blur">
                <div className="mx-auto flex h-14 w-full max-w-md items-center gap-3 px-4">
                    <img src="/brand/logo-2d.svg" alt="" className="size-7" />
                    <div className="min-w-0 flex-1">
                        <h1 className="truncate text-base font-semibold">{title}</h1>
                        {props.auth?.organization?.name && <p className="truncate text-xs text-muted-foreground">{props.auth.organization.name}</p>}
                    </div>
                    <Button variant="ghost" size="icon" aria-label="Keluar" onClick={() => router.post('/keluar')}><LogOut /></Button>
                </div>
            </header>
            <main className="mx-auto grid w-full max-w-md flex-1 content-start gap-4 px-4 pt-4 pb-24">{children}</main>
            <nav aria-label="Menu anggota" className="fixed inset-x-0 bottom-0 z-10 border-t bg-background/95 pb-[env(safe-area-inset-bottom)] backdrop-blur">
                <ul className="mx-auto grid max-w-md grid-cols-4">
                    {tabs.map((t) => {
                        const active = t.href === '/saya' ? path === '/saya' : path.startsWith(t.href);
                        return (
                            <li key={t.href}>
                                <Link href={t.href} aria-current={active ? 'page' : undefined}
                                    className={cn('flex flex-col items-center gap-1 py-2 text-xs', active ? 'font-semibold text-foreground' : 'text-muted-foreground')}>
                                    <span className={cn('flex h-7 w-12 items-center justify-center rounded-full', active && 'bg-accent')}><t.icon className="size-5" /></span>
                                    {t.label}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </nav>
            <FlashToaster position="top-center" />
        </div>
    );
}
