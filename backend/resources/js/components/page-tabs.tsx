import { router } from '@inertiajs/react';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';

/** Tab yang tersimpan di URL (?tab=...), sehingga bisa dibagikan dan di-refresh. */
export function PageTabs({ tabs, current, param = 'tab' }: { tabs: Record<string, string>; current: string; param?: string }) {
    const go = (value: string) => {
        const url = new URL(window.location.href);
        url.searchParams.set(param, value);
        url.searchParams.delete('page');
        router.get(url.pathname + url.search, {}, { preserveScroll: true });
    };

    return (
        <Tabs value={current} onValueChange={(v) => go(String(v))}>
            <TabsList variant="line">
                {Object.entries(tabs).map(([key, label]) => <TabsTrigger key={key} value={key}>{label}</TabsTrigger>)}
            </TabsList>
        </Tabs>
    );
}
