import { router, useForm } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/data-table';
import { FormField } from '@/components/form-field';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { TableCell, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { fmtDateTime, fmtSize } from '@/lib/format';

type Doc = { id: number; title: string; extension: string; size: number; for_members: boolean; created_at: string; by: string | null };
export type MinutesData = {
    minutes: { content: string; version: number; published_at: string | null; updated_at: string | null; editor: string | null };
    documents: Doc[]; max: number;
};

const accept = '.pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx';

/** Notulen (draf → terbit) dan dokumen kegiatan. */
export function MinutesTab({ eventId, data }: { eventId: number; data: MinutesData }) {
    const m = data.minutes;
    const form = useForm({ content: m.content, version: m.version });
    const doc = useForm<{ title: string; for_members: string; file: File | null }>({ title: '', for_members: '1', file: null });
    const [fileKey, setFileKey] = useState(0);
    const dirty = form.data.content !== m.content;
    const save = (e: FormEvent) => {
        e.preventDefault();
        // Saat bentrok, tulisan & versi lama dipertahankan agar tidak menimpa pengurus lain.
        form.put(`/kegiatan/${eventId}/notulen`, { preserveScroll: true, onSuccess: (page) => form.setData('version', (page.props as unknown as { minutes: MinutesData }).minutes.minutes.version) });
    };
    const upload = (e: FormEvent) => {
        e.preventDefault();
        doc.post(`/kegiatan/${eventId}/dokumen`, { preserveScroll: true, forceFormData: true, onSuccess: () => { doc.reset(); setFileKey((k) => k + 1); } });
    };
    return (
        <>
            <Card className="max-w-4xl">
                <CardHeader>
                    <CardTitle className="flex flex-wrap items-center gap-2">Notulen {m.version > 0 && <StatusBadge status={m.published_at ? 'approved' : 'pending'} label={m.published_at ? 'Terbit' : 'Draf'} />}</CardTitle>
                    <CardDescription>
                        {m.version === 0 ? 'Belum ada notulen. Draf hanya terlihat pengurus sampai diterbitkan.'
                            : `Terakhir diubah ${fmtDateTime(m.updated_at)}${m.editor ? ` oleh ${m.editor}` : ''}. ${m.published_at ? 'Peserta langsung melihat perubahan yang disimpan.' : 'Peserta belum bisa melihatnya.'}`}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={save} className="grid gap-4">
                        <FormField label="Isi notulen" hint={`${form.data.content.length.toLocaleString('id-ID')} dari 20.000 karakter`} error={form.errors.content}>
                            <Textarea className="min-h-56" required maxLength={20000} value={form.data.content} onChange={(e) => form.setData('content', e.target.value)} />
                        </FormField>
                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" disabled={form.processing || !dirty}>{form.processing ? 'Menyimpan…' : m.published_at ? 'Simpan perubahan' : 'Simpan draf'}</Button>
                            {m.version > 0 && !dirty && (
                                <ConfirmAction title={m.published_at ? 'Tarik notulen?' : 'Terbitkan notulen?'} confirmLabel={m.published_at ? 'Tarik' : 'Terbitkan'} href={`/kegiatan/${eventId}/notulen/terbit`} data={{ published: m.published_at ? 0 : 1 }}
                                    description={m.published_at ? 'Notulen kembali menjadi draf dan tidak terlihat peserta.' : 'Semua peserta kegiatan bisa membaca notulen ini dan mendapat notifikasi.'}>
                                    {m.published_at ? 'Tarik jadi draf' : 'Terbitkan'}
                                </ConfirmAction>
                            )}
                        </div>
                    </form>
                </CardContent>
            </Card>
            <Card className="max-w-4xl">
                <CardHeader><CardTitle>Tambah dokumen</CardTitle><CardDescription>PDF, JPG, PNG, Word, Excel, atau PowerPoint. Maksimal 5 MB per berkas, {data.max} berkas per kegiatan.</CardDescription></CardHeader>
                <CardContent>
                    <form onSubmit={upload} className="grid items-start gap-4 sm:grid-cols-3">
                        <FormField label="Judul" error={doc.errors.title}><Input required maxLength={150} value={doc.data.title} onChange={(e) => doc.setData('title', e.target.value)} /></FormField>
                        <FormField label="Terlihat oleh" error={doc.errors.for_members}>
                            <NativeSelect className="w-full" value={doc.data.for_members} onChange={(e) => doc.setData('for_members', e.target.value)}>
                                <NativeSelectOption value="1">Peserta kegiatan</NativeSelectOption><NativeSelectOption value="0">Hanya pengurus</NativeSelectOption>
                            </NativeSelect>
                        </FormField>
                        <FormField label="Berkas" error={doc.errors.file}><Input key={fileKey} type="file" required accept={accept} onChange={(e) => doc.setData('file', e.target.files?.[0] ?? null)} /></FormField>
                        <div><Button type="submit" disabled={doc.processing || data.documents.length >= data.max}>{doc.processing ? 'Mengunggah…' : 'Unggah'}</Button></div>
                    </form>
                </CardContent>
            </Card>
            <DataTable head={['Dokumen', 'Ukuran', 'Terlihat oleh', '']} count={data.documents.length} empty="Belum ada dokumen.">
                {data.documents.map((d) => (
                    <TableRow key={d.id}>
                        <TableCell className="max-w-xs whitespace-normal">{d.title}<div className="text-xs text-muted-foreground">{d.extension.toUpperCase()} · {fmtDateTime(d.created_at)} · {d.by ?? '-'}</div></TableCell>
                        <TableCell className="tabular-nums">{fmtSize(d.size)}</TableCell>
                        <TableCell>
                            <NativeSelect size="sm" aria-label={`${d.title} terlihat oleh`} value={d.for_members ? '1' : '0'} onChange={(e) => router.patch(`/kegiatan-dokumen/${d.id}`, { for_members: e.target.value }, { preserveScroll: true })}>
                                <NativeSelectOption value="1">Peserta kegiatan</NativeSelectOption><NativeSelectOption value="0">Hanya pengurus</NativeSelectOption>
                            </NativeSelect>
                        </TableCell>
                        <TableCell>
                            <div className="flex flex-wrap justify-end gap-1.5">
                                <Button size="sm" variant="outline" render={<a href={`/kegiatan-dokumen/${d.id}`} aria-label={`Unduh ${d.title}`} />}><Download /> Unduh</Button>
                                <ConfirmAction size="sm" destructive title={`Hapus ${d.title}?`} description="Berkas dihapus permanen dan tidak bisa dikembalikan." href={`/kegiatan-dokumen/${d.id}`} method="delete" confirmLabel="Hapus">Hapus</ConfirmAction>
                            </div>
                        </TableCell>
                    </TableRow>
                ))}
            </DataTable>
        </>
    );
}
