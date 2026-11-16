export type Role = 'super_admin' | 'division_admin' | 'scanner' | 'member';

export interface SharedProps {
    appName: string;
    auth: {
        user: { id: number; name: string; email: string; role: Role; division_id: number | null };
        organization: { id: number; name: string } | null;
        isSuperAdmin: boolean;
    } | null;
    navBadges: { excuses: number; reviews: number } | null;
    flash: { status: string | null };
    errors: Record<string, string>;
    [key: string]: unknown;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}
