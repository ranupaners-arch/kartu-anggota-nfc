import { router } from '@inertiajs/react';
import { CalendarDays, CornerDownLeft, Search, UserRound, type LucideIcon } from 'lucide-react';
import { Fragment, type KeyboardEvent, useEffect, useId, useMemo, useRef, useState } from 'react';
import { useNavigation } from '@/components/app-sidebar';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { fmtDate } from '@/lib/format';
import { cn } from '@/lib/utils';

type Result = { key: string; group: string; label: string; detail?: string; href: string; icon: LucideIcon };
type Found = { members: { id: number; member_no: string; name: string }[]; events: { id: number; title: string; location: string | null; starts_at: string }[] };

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);

/** Cari anggota & kegiatan dari server (tunda 200 ms, permintaan lama dibatalkan). */
function useFound(q: string) {
    const [found, setFound] = useState<Found>({ members: [], events: [] });
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        if (q.trim().length < 2) {
            setFound({ members: [], events: [] });
            return;
        }
        const ctl = new AbortController();
        const t = setTimeout(() => {
            setLoading(true);
            fetch(`/cari?${new URLSearchParams({ q: q.trim() })}`, { headers: { Accept: 'application/json' }, signal: ctl.signal })
                .then((r) => (r.ok ? r.json() : { members: [], events: [] }))
                .then(setFound)
                .catch(() => {})
                .finally(() => setLoading(false));
        }, 200);
        return () => { clearTimeout(t); ctl.abort(); };
    }, [q]);
    return { found, loading };
}

/**
 * Pencarian cepat: tombol di header + pintasan Ctrl+K / ⌘K. Pola combobox: fokus tetap di kotak
 * isian, panah atas/bawah memilih hasil (aria-activedescendant), Enter membuka, Esc menutup.
 */
export function QuickSearch() {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [active, setActive] = useState(0);
    const nav = useNavigation();
    const { found, loading } = useFound(q);
    const listId = useId();
    const listRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const onKey = (e: globalThis.KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOpen((o) => !o);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const results = useMemo<Result[]>(() => {
        const term = q.trim().toLowerCase();
        const pages = nav.flatMap((g) => g.items)
            .filter((i) => !term || i.label.toLowerCase().includes(term))
            .map((i) => ({ key: `p${i.href}`, group: 'Halaman', label: i.label, href: i.href, icon: i.icon }));
        return [
            ...pages,
            ...found.members.map((m) => ({ key: `m${m.id}`, group: 'Anggota', label: m.name, detail: m.member_no, href: `/anggota/${m.id}`, icon: UserRound })),
            ...found.events.map((e) => ({ key: `e${e.id}`, group: 'Kegiatan', label: e.title, detail: [fmtDate(e.starts_at), e.location].filter(Boolean).join(' · '), href: `/kegiatan/${e.id}`, icon: CalendarDays })),
        ];
    }, [q, nav, found]);

    useEffect(() => setActive(0), [results.length, q]);
    useEffect(() => {
        listRef.current?.querySelector(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    const go = (r?: Result) => {
        if (!r) return;
        setOpen(false);
        router.visit(r.href);
    };
    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(results.length - 1, a + 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(0, a - 1)); }
        else if (e.key === 'Enter') { e.preventDefault(); go(results[active]); }
    };
    const changeOpen = (o: boolean) => {
        setOpen(o);
        if (!o) setQ('');
    };
    const optionId = (i: number) => `${listId}-opt-${i}`;
    const typing = q.trim().length >= 2;

    return (
        <>
            <Button variant="outline" size="sm" className="gap-2 text-muted-foreground" onClick={() => setOpen(true)} aria-keyshortcuts={isMac ? 'Meta+K' : 'Control+K'}>
                <Search /><span className="hidden sm:inline">Cari…</span>
                <kbd className="hidden rounded border bg-muted px-1.5 font-sans text-[10px] font-medium sm:inline">{isMac ? '⌘' : 'Ctrl'} K</kbd>
            </Button>
            <Dialog open={open} onOpenChange={changeOpen}>
                <DialogContent showCloseButton={false} className="top-[15%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-lg">
                    <DialogTitle className="sr-only">Pencarian cepat</DialogTitle>
                    <DialogDescription className="sr-only">Cari halaman, anggota, atau kegiatan. Panah untuk memilih, Enter untuk membuka.</DialogDescription>
                    <div className="flex items-center gap-2 border-b px-3">
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <input autoFocus value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={onKeyDown}
                            placeholder="Cari halaman, anggota (nama/nomor), kegiatan…"
                            role="combobox" aria-expanded aria-controls={listId} aria-autocomplete="list" aria-label="Pencarian cepat"
                            aria-activedescendant={results[active] ? optionId(active) : undefined}
                            className="h-12 w-full bg-transparent text-base outline-none placeholder:text-muted-foreground sm:text-sm" />
                    </div>
                    <div ref={listRef} id={listId} role="listbox" aria-label="Hasil pencarian" className="max-h-[min(60vh,420px)] overflow-y-auto p-1.5">
                        {results.map((r, i) => (
                            <Fragment key={r.key}>
                                {(i === 0 || results[i - 1].group !== r.group) && (
                                    <div role="presentation" className="px-2 pt-2 pb-1 text-xs font-medium text-muted-foreground">{r.group}</div>
                                )}
                                <div id={optionId(i)} role="option" aria-selected={i === active} data-index={i}
                                    onMouseMove={() => setActive(i)} onClick={() => go(r)}
                                    className={cn('flex cursor-pointer items-center gap-3 rounded-md px-2 py-2', i === active && 'bg-accent text-accent-foreground')}>
                                    <r.icon className="size-4 shrink-0 text-muted-foreground" />
                                    <span className="min-w-0 flex-1 truncate">{r.label}</span>
                                    {r.detail && <span className="shrink-0 truncate text-xs text-muted-foreground">{r.detail}</span>}
                                    {i === active && <CornerDownLeft className="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />}
                                </div>
                            </Fragment>
                        ))}
                    </div>
                    <p role="status" className="border-t px-3 py-2 text-xs text-muted-foreground">
                        {loading ? 'Mencari…'
                            : !typing ? 'Ketik minimal 2 huruf untuk mencari anggota & kegiatan.'
                            : found.members.length + found.events.length === 0 ? `Tidak ada anggota/kegiatan yang cocok dengan "${q.trim()}".`
                            : `${found.members.length} anggota, ${found.events.length} kegiatan.`}
                    </p>
                </DialogContent>
            </Dialog>
        </>
    );
}
