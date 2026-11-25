import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';

/** Tab yang tersimpan di URL (?tab=...), sehingga bisa dibagikan dan di-refresh. */
export function PageTabs({ tabs, current, param = 'tab' }: { tabs: Record<string, string>; current: string; param?: string }) {
    const box = useRef<HTMLDivElement>(null);
    // Tab aktif digeser ke dalam layar (hanya mendatar, halaman tidak ikut bergulir).
    useEffect(() => {
        const show = () => {
            const el = box.current, active = el?.querySelector<HTMLElement>('[aria-selected=true]');
            if (el && active) el.scrollLeft += active.getBoundingClientRect().left - el.getBoundingClientRect().left - (el.clientWidth - active.offsetWidth) / 2;
        };
        show();
        document.fonts?.ready.then(show); // lebar tab berubah setelah font dimuat
    }, [current]);
    const go = (value: string) => {
        const url = new URL(window.location.href);
        url.searchParams.set(param, value);
        url.searchParams.delete('page');
        router.get(url.pathname + url.search, {}, { preserveScroll: true });
    };

    return (
        // Di layar sempit tab bisa digeser ke samping, bukan meluberkan halaman.
        <Tabs ref={box} value={current} onValueChange={(v) => go(String(v))} className="max-w-full overflow-x-auto [scrollbar-width:none]">
            <TabsList variant="line" className="w-max">
                {Object.entries(tabs).map(([key, label]) => <TabsTrigger key={key} value={key}>{label}</TabsTrigger>)}
            </TabsList>
        </Tabs>
    );
}
