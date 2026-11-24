import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { TooltipProvider } from '@/components/ui/tooltip';
import AppLayout, { FlashToaster } from '@/layouts/app-layout';
import type { SharedProps } from '@/types';

/** Pengurus dashboard memakai kerangka dashboard; bendahara/sekretaris lain hanya halaman iuran. */
export default function FinanceLayout({ title, actions, children }: { title: string; actions?: ReactNode; children: ReactNode }) {
    const { auth } = usePage<SharedProps>().props;
    const role = auth?.user.role;
    if (role === 'super_admin' || role === 'division_admin') return <AppLayout title={title} actions={actions}>{children}</AppLayout>;
    return (
        <TooltipProvider>
            <div className="min-h-dvh bg-background">
                <header className="flex h-14 items-center gap-3 border-b px-4">
                    <Button variant="ghost" size="icon" aria-label="Kembali" render={<Link href={role === 'scanner' ? '/pindai' : '/saya/akun'} />}><ArrowLeft /></Button>
                    <div className="min-w-0 flex-1">
                        <h1 className="truncate text-sm font-medium">{title}</h1>
                        <p className="truncate text-xs text-muted-foreground">{auth?.organization?.name}</p>
                    </div>
                </header>
                <main className="mx-auto flex w-full min-w-0 max-w-6xl flex-col gap-4 p-4">
                    {actions && <div className="flex flex-wrap items-center justify-end gap-2">{actions}</div>}
                    {children}
                </main>
            </div>
            <FlashToaster />
        </TooltipProvider>
    );
}
