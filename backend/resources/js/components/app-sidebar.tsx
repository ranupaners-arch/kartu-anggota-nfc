import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3, CalendarDays, ClipboardCheck, CreditCard, FileClock, House, ScrollText, Settings, ShieldAlert, Smartphone, UserCog, Users,
    type LucideIcon,
} from 'lucide-react';
import {
    Sidebar, SidebarContent, SidebarGroup, SidebarGroupLabel, SidebarHeader, SidebarMenu, SidebarMenuBadge, SidebarMenuButton,
    SidebarMenuItem, SidebarRail,
} from '@/components/ui/sidebar';
import type { SharedProps } from '@/types';

type Item = { label: string; href: string; icon: LucideIcon; badge?: number };
type Group = { label?: string; items: Item[] };

/** Navigasi dua tingkat: menu (grup) → submenu (halaman). */
function useNavigation(): Group[] {
    const { auth, navBadges } = usePage<SharedProps>().props;
    const superAdmin = auth?.isSuperAdmin ?? false;

    return [
        { items: [{ label: 'Beranda', href: '/', icon: House }] },
        {
            label: 'Operasional',
            items: [
                { label: 'Kegiatan', href: '/kegiatan', icon: CalendarDays },
                { label: 'Pengajuan Izin', href: '/izin', icon: ClipboardCheck, badge: navBadges?.excuses },
                { label: 'Perlu Ditinjau', href: '/tinjauan', icon: ShieldAlert, badge: navBadges?.reviews },
            ],
        },
        {
            label: 'Data',
            items: [
                { label: 'Anggota', href: '/anggota', icon: Users },
                ...(superAdmin ? [{ label: 'Kartu', href: '/kartu', icon: CreditCard }] : []),
                { label: 'Rekap & Ekspor', href: '/rekap', icon: BarChart3 },
            ],
        },
        ...(superAdmin
            ? [{
                label: 'Administrasi',
                items: [
                    { label: 'Pengguna & Peran', href: '/pengguna', icon: UserCog },
                    { label: 'Perangkat Petugas', href: '/perangkat', icon: Smartphone },
                    { label: 'Log Aktivitas', href: '/log', icon: ScrollText },
                    { label: 'Pengaturan', href: '/pengaturan', icon: Settings },
                ],
            }]
            : []),
    ];
}

function isActive(current: string, href: string) {
    const path = current.split('?')[0];
    return href === '/' ? path === '/' : path === href || path.startsWith(`${href}/`);
}

export function AppSidebar() {
    const { url, props } = usePage<SharedProps>();

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" render={<Link href="/" />}>
                            <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                                <FileClock className="size-4" />
                            </div>
                            <div className="grid flex-1 text-left text-sm leading-tight">
                                <span className="truncate font-semibold">{props.auth?.organization?.name ?? props.appName}</span>
                                <span className="truncate text-xs text-muted-foreground">Kartu Anggota</span>
                            </div>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>
            <SidebarContent>
                {useNavigation().map((group, i) => (
                    <SidebarGroup key={group.label ?? i}>
                        {group.label && <SidebarGroupLabel>{group.label}</SidebarGroupLabel>}
                        <SidebarMenu>
                            {group.items.map((item) => (
                                <SidebarMenuItem key={item.href}>
                                    <SidebarMenuButton isActive={isActive(url, item.href)} tooltip={item.label} render={<Link href={item.href} />}>
                                        <item.icon />
                                        <span>{item.label}</span>
                                    </SidebarMenuButton>
                                    {item.badge ? <SidebarMenuBadge>{item.badge}</SidebarMenuBadge> : null}
                                </SidebarMenuItem>
                            ))}
                        </SidebarMenu>
                    </SidebarGroup>
                ))}
            </SidebarContent>
            <SidebarRail />
        </Sidebar>
    );
}
