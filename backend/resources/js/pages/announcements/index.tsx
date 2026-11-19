import { Head, useForm } from '@inertiajs/react';
import { Pencil, Pin, Plus } from 'lucide-react';
import { type FormEvent, type ReactElement, useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { FormField } from '@/components/form-field';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { fmtDateTime, toLocalInput } from '@/lib/format';
import type { Paginated } from '@/types';

type Item = {
    id: number; title: string; body: string; pinned: boolean; expires_at: string | null; created_at: string; division_id: number | null;
    division: string | null; author: string | null; expired: boolean; canEdit: boolean;
};
type Div = { id: number; name: string };

/** Formulir buat/ubah pengumuman dalam dialog. */
function AnnouncementForm({ item, divisions, canTargetAll, trigger }: { item?: Item; divisions: Div[]; canTargetAll: boolean; trigger: ReactElement }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        title: item?.title ?? '', body: item?.body ?? '', pinned: item?.pinned ?? false,
        division_id: item?.division_id ? String(item.division_id) : (canTargetAll ? '' : String(divisions[0]?.id ?? '')),
        expires_at: toLocalInput(item?.expires_at),
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => { setOpen(false); if (!item) form.reset(); } };
        if (item) form.put(`/pengumuman/${item.id}`, opts); else form.post('/pengumuman', opts);
    };
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={trigger} />
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{item ? 'Ubah pengumuman' : 'Pengumuman baru'}</DialogTitle>
                        <DialogDescription>Tampil di app anggota (tab Kegiatan) sesuai sasaran.</DialogDescription>
                    </DialogHeader>
                    <FormField label="Judul" error={form.errors.title}><Input required maxLength={150} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} /></FormField>
                    <FormField label="Isi" error={form.errors.body}><Textarea required rows={5} maxLength={5000} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} /></FormField>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField label="Untuk" error={form.errors.division_id}>
                            <NativeSelect className="w-full" value={form.data.division_id} onChange={(e) => form.setData('division_id', e.target.value)} disabled={!canTargetAll}>
                                {canTargetAll && <NativeSelectOption value="">Semua anggota</NativeSelectOption>}
                                {divisions.map((d) => <NativeSelectOption key={d.id} value={d.id}>Divisi {d.name}</NativeSelectOption>)}
                            </NativeSelect>
                        </FormField>
                        <FormField label="Berlaku sampai" hint="Kosongkan bila tidak kedaluwarsa." error={form.errors.expires_at}>
                            <Input type="datetime-local" value={form.data.expires_at} onChange={(e) => form.setData('expires_at', e.target.value)} />
                        </FormField>
                    </div>
                    <Label className="flex items-center gap-2 font-normal"><Checkbox checked={form.data.pinned} onCheckedChange={(v) => form.setData('pinned', v === true)} /> Sematkan di atas</Label>
                    <DialogFooter><Button type="submit" disabled={form.processing}>{item ? 'Simpan' : 'Terbitkan'}</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Announcements({ items, divisions, canTargetAll }: { items: Paginated<Item>; divisions: Div[]; canTargetAll: boolean }) {
    return (
        <AppLayout title="Pengumuman" actions={<AnnouncementForm divisions={divisions} canTargetAll={canTargetAll} trigger={<Button><Plus /> Pengumuman Baru</Button>} />}>
            <Head title="Pengumuman" />
            {items.data.length === 0 && <p className="text-sm text-muted-foreground">Belum ada pengumuman.</p>}
            <div className="grid gap-3">
                {items.data.map((a) => (
                    <Card key={a.id} className={a.expired ? 'opacity-60' : undefined}>
                        <CardHeader>
                            <CardTitle className="flex flex-wrap items-center gap-2">
                                {a.pinned && <Pin className="size-4 text-[var(--chart-bar)]" aria-label="Disematkan" />}
                                {a.title}
                                {a.expired && <StatusBadge status="closed" label="Kedaluwarsa" />}
                            </CardTitle>
                            <CardDescription>
                                {a.division ? `Divisi ${a.division}` : 'Semua anggota'} · {fmtDateTime(a.created_at)}{a.author ? ` · ${a.author}` : ''}
                                {a.expires_at && !a.expired ? ` · berlaku s.d. ${fmtDateTime(a.expires_at)}` : ''}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            <p className="whitespace-pre-line text-sm">{a.body}</p>
                            {a.canEdit && (
                                <div className="flex flex-wrap gap-2">
                                    <AnnouncementForm item={a} divisions={divisions} canTargetAll={canTargetAll} trigger={<Button size="sm" variant="outline"><Pencil /> Ubah</Button>} />
                                    <ConfirmAction size="sm" destructive title="Hapus pengumuman?" description="Pengumuman hilang dari app anggota." href={`/pengumuman/${a.id}`} method="delete" confirmLabel="Hapus">Hapus</ConfirmAction>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ))}
            </div>
            <Pager page={items} />
        </AppLayout>
    );
}
