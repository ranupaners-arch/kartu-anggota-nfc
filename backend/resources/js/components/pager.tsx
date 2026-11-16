import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

export function Pager({ page }: { page: Paginated<unknown> }) {
    if (page.last_page <= 1) return null;
    const prev = page.links[0]?.url;
    const next = page.links[page.links.length - 1]?.url;

    return (
        <div className="flex items-center justify-between gap-2 text-sm text-muted-foreground">
            <span>Halaman {page.current_page} dari {page.last_page} · {page.total} data</span>
            <div className="flex gap-1">
                <Button variant="outline" size="sm" disabled={!prev} render={prev ? <Link href={prev} preserveScroll /> : undefined}>
                    <ChevronLeft /> Sebelumnya
                </Button>
                <Button variant="outline" size="sm" disabled={!next} render={next ? <Link href={next} preserveScroll /> : undefined}>
                    Berikutnya <ChevronRight />
                </Button>
            </div>
        </div>
    );
}
