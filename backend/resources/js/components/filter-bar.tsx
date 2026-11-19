import { router } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { type FormEvent, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/**
 * Baris pencarian & filter untuk halaman daftar. Nilai kosong tidak dikirim ke URL; `keep` (mis. tab)
 * selalu ikut. Tombol Atur ulang muncul bila ada filter aktif.
 */
export function FilterBar({ url, values, keep = {}, search, onSearch, placeholder = 'Cari…', children }: {
    url: string;
    values: Record<string, string>;
    keep?: Record<string, string>;
    search: string;
    onSearch: (v: string) => void;
    placeholder?: string;
    children?: ReactNode;
}) {
    const clean = (o: Record<string, string>) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== ''));
    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get(url, { ...keep, ...clean(values) }, { preserveState: true, preserveScroll: true });
    };
    const active = Object.values(values).some((v) => v !== '');
    return (
        <form onSubmit={apply} className="flex flex-wrap items-center gap-2">
            <div className="relative w-full sm:w-64">
                <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input className="pl-8" placeholder={placeholder} value={search} onChange={(e) => onSearch(e.target.value)} aria-label={placeholder} />
            </div>
            {children}
            <Button type="submit" variant="secondary">Terapkan</Button>
            {active && <Button type="button" variant="ghost" onClick={() => router.get(url, keep)}><X /> Atur ulang</Button>}
        </form>
    );
}
