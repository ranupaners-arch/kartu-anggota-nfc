import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, LogOut, Wallet } from 'lucide-react';
import { type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { FlashToaster } from '@/layouts/app-layout';
import type { SharedProps } from '@/types';

/** Kerangka mode petugas di browser: satu kolom, untuk HP. */
export default function ScannerLayout({ title, subtitle, back, children }: { title: string; subtitle?: string; back?: string; children: ReactNode }) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <div className="flex min-h-dvh flex-col bg-background">
            <header className="sticky top-0 z-10 border-b bg-background/95 backdrop-blur">
                <div className="mx-auto flex h-14 w-full max-w-md items-center gap-2 px-3">
                    {back ? (
                        <Button variant="ghost" size="icon" aria-label="Kembali" render={<Link href={back} />}><ArrowLeft /></Button>
                    ) : (
                        <img src="/brand/logo-2d.svg" alt="" className="mx-1 size-7" />
                    )}
                    <div className="min-w-0 flex-1">
                        <h1 className="truncate text-base font-semibold">{title}</h1>
                        {subtitle && <p className="truncate text-xs text-muted-foreground">{subtitle}</p>}
                    </div>
                    {auth?.canViewDues && <Button variant="ghost" size="icon" aria-label="Iuran" render={<Link href="/iuran" />}><Wallet /></Button>}
                    <Button variant="ghost" size="icon" aria-label="Keluar" onClick={() => router.post('/keluar')}><LogOut /></Button>
                </div>
            </header>
            <main className="mx-auto grid w-full max-w-md flex-1 content-start gap-4 p-4">{children}</main>
            <FlashToaster position="top-center" />
        </div>
    );
}
