import { Link, router, usePage } from '@inertiajs/react';
import { ChevronsUpDown, LogOut, Monitor, Moon, Sun, UserRound } from 'lucide-react';
import { useTheme } from 'next-themes';
import { type ReactNode, useEffect } from 'react';
import { toast } from 'sonner';
import { AppSidebar } from '@/components/app-sidebar';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuRadioGroup, DropdownMenuRadioItem,
    DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { roleLabel } from '@/lib/format';
import type { SharedProps } from '@/types';

/** Tampilkan pesan flash dari server sebagai toast. */
function FlashToaster() {
    const { flash, errors } = usePage<SharedProps>().props;
    useEffect(() => {
        if (flash?.status) toast.success(flash.status);
    }, [flash?.status]);
    useEffect(() => {
        const first = Object.values(errors ?? {})[0];
        if (first) toast.error(first);
    }, [errors]);
    return <Toaster position="top-right" richColors />;
}

function UserMenu() {
    const user = usePage<SharedProps>().props.auth?.user;
    const { theme, setTheme } = useTheme();
    if (!user) return null;
    const initials = user.name.split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger render={<Button variant="ghost" className="h-9 gap-2 px-2" />}>
                <Avatar className="size-7"><AvatarFallback className="text-xs">{initials}</AvatarFallback></Avatar>
                <span className="hidden text-sm sm:inline">{user.name}</span>
                <ChevronsUpDown className="size-4 text-muted-foreground" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                {/* GroupLabel Base UI wajib di dalam grup; tanpa grup menu gagal dirender. */}
                <DropdownMenuGroup>
                    <DropdownMenuLabel>
                        <div className="text-sm font-medium text-foreground">{user.name}</div>
                        <div className="text-xs font-normal text-muted-foreground">{roleLabel[user.role]}</div>
                    </DropdownMenuLabel>
                </DropdownMenuGroup>
                <DropdownMenuSeparator />
                <DropdownMenuItem render={<Link href="/akun" />}><UserRound /> Akun Saya</DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuRadioGroup value={theme ?? 'system'} onValueChange={(v) => setTheme(String(v))}>
                    <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">Tampilan</DropdownMenuLabel>
                    <DropdownMenuRadioItem value="light"><Sun /> Terang</DropdownMenuRadioItem>
                    <DropdownMenuRadioItem value="dark"><Moon /> Gelap</DropdownMenuRadioItem>
                    <DropdownMenuRadioItem value="system"><Monitor /> Ikuti sistem</DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={() => router.post('/keluar')}><LogOut /> Keluar</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export default function AppLayout({ title, actions, children }: { title: string; actions?: ReactNode; children: ReactNode }) {
    return (
        <TooltipProvider>
            <SidebarProvider>
                <AppSidebar />
                <SidebarInset>
                    <header className="flex h-14 shrink-0 items-center gap-2 border-b px-4">
                        <SidebarTrigger className="-ml-1" />
                        <Separator orientation="vertical" className="mr-2 h-4" />
                        <h1 className="flex-1 truncate text-sm font-medium">{title}</h1>
                        <UserMenu />
                    </header>
                    <main className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                        {actions && <div className="flex flex-wrap items-center justify-end gap-2">{actions}</div>}
                        {children}
                    </main>
                </SidebarInset>
            </SidebarProvider>
            <FlashToaster />
        </TooltipProvider>
    );
}
