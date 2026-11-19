import { X } from 'lucide-react';
import { type ReactNode, useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';

/**
 * Pilihan baris untuk tindakan massal. Pilihan hanya berlaku untuk baris di halaman ini: berganti
 * halaman/filter (daftar id berubah) atau sesudah tindakan berhasil → pilihan dikosongkan.
 */
export function useSelection(ids: number[]) {
    const key = ids.join(',');
    const [selected, setSelected] = useState<Set<number>>(new Set());
    useEffect(() => setSelected(new Set()), [key]);
    return useMemo(() => {
        const count = ids.filter((id) => selected.has(id)).length;
        return {
            ids: ids.filter((id) => selected.has(id)),
            count,
            total: ids.length,
            all: ids.length > 0 && count === ids.length,
            some: count > 0 && count < ids.length,
            has: (id: number) => selected.has(id),
            toggle: (id: number) => setSelected((s) => {
                const n = new Set(s);
                if (n.has(id)) n.delete(id);
                else n.add(id);
                return n;
            }),
            toggleAll: () => setSelected(count === ids.length ? new Set() : new Set(ids)),
            clear: () => setSelected(new Set()),
        };
    }, [key, selected]); // eslint-disable-line react-hooks/exhaustive-deps
}

export type Selection = ReturnType<typeof useSelection>;

/** Kotak "pilih semua" untuk judul kolom; setengah tercentang bila hanya sebagian yang dipilih. */
export function SelectAll({ sel }: { sel: Selection }) {
    return <Checkbox aria-label="Pilih semua baris di halaman ini" checked={sel.all} indeterminate={sel.some} onCheckedChange={sel.toggleAll} />;
}

export function SelectRow({ sel, id, label }: { sel: Selection; id: number; label: string }) {
    return <Checkbox aria-label={`Pilih ${label}`} checked={sel.has(id)} onCheckedChange={() => sel.toggle(id)} />;
}

/** Bilah tindakan massal yang menempel di bawah layar selama ada baris terpilih. */
export function BulkBar({ sel, children }: { sel: Selection; children: ReactNode }) {
    return (
        <>
            {/* Wilayah status selalu ada di halaman agar perubahan jumlah terpilih dibacakan andal. */}
            <span role="status" className="sr-only">{sel.count > 0 ? `${sel.count} baris dipilih` : ''}</span>
            <div role="region" aria-label="Tindakan massal" hidden={sel.count === 0}
                className="sticky bottom-4 z-10 flex flex-wrap items-center gap-2 rounded-xl border bg-popover p-2 pl-4 text-popover-foreground shadow-lg">
                <span className="mr-auto flex items-center gap-2 text-sm font-medium tabular-nums">
                    <span aria-hidden="true">{sel.count} dipilih</span>
                    {/* Di HP judul kolom (dan kotak pilih semua) disembunyikan, jadi sediakan juga di sini. */}
                    {!sel.all && <Button variant="link" size="sm" className="h-auto px-0" onClick={sel.toggleAll}>Pilih semua ({sel.total})</Button>}
                </span>
                {children}
                <Button variant="ghost" size="icon-sm" aria-label="Batalkan pilihan" onClick={sel.clear}><X /></Button>
            </div>
        </>
    );
}
