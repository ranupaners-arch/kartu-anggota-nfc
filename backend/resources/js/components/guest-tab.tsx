import { useForm } from '@inertiajs/react';
import { Copy, Download, ExternalLink } from 'lucide-react';
import { type FormEvent } from 'react';
import { toast } from 'sonner';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/data-table';
import { FormField } from '@/components/form-field';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { TableCell, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { fmtDateTime } from '@/lib/format';

type Guest = { id: number; name: string; institution: string | null; revoked_at: string | null; purged: boolean; url: string | null; checkins: { session: string; status: string; at: string }[] };
export type GuestData = { max: number; present: number; items: Guest[] };

async function copy(url: string) {
    try {
        await navigator.clipboard.writeText(url);
        toast.success('Tautan QR disalin.');
    } catch {
        toast.error('Tidak bisa menyalin. Buka tautannya lalu salin alamatnya.');
    }
}

/** Peserta tamu: daftar, tautan QR, dan kehadiran. */
export function GuestTab({ eventId, closed, data }: { eventId: number; closed: boolean; data: GuestData }) {
    const form = useForm({ lines: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post(`/kegiatan/${eventId}/tamu`, { preserveScroll: true, onSuccess: () => form.reset() }); };
    return (
        <>
            {!closed && (
                <Card className="max-w-3xl">
                    <CardHeader><CardTitle>Tambah tamu</CardTitle><CardDescription>QR tamu butuh internet. Tamu tidak masuk rekap anggota.</CardDescription></CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="grid gap-4">
                            <FormField label="Daftar tamu" hint={`Satu baris satu tamu: Nama atau Nama; Instansi. Maksimal ${data.max} per kegiatan.`} error={form.errors.lines}>
                                <Textarea className="min-h-24" required maxLength={60000} placeholder={'Budi Santoso; Universitas Contoh\nSiti Aminah'} value={form.data.lines} onChange={(e) => form.setData('lines', e.target.value)} />
                            </FormField>
                            <div><Button type="submit" disabled={form.processing}>{form.processing ? 'Menambahkan…' : 'Tambah'}</Button></div>
                        </form>
                    </CardContent>
                </Card>
            )}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm text-muted-foreground">{data.items.length} tamu, {data.present} hadir.</p>
                {data.items.length > 0 && <Button size="sm" variant="outline" render={<a href={`/kegiatan/${eventId}/tamu/ekspor`} />}><Download /> CSV</Button>}
            </div>
            <DataTable head={['Tamu', 'Kehadiran', 'QR', '']} count={data.items.length} empty="Belum ada tamu.">
                {data.items.map((g) => (
                    <TableRow key={g.id}>
                        <TableCell className="max-w-xs whitespace-normal">{g.name}{g.institution && <div className="text-xs text-muted-foreground">{g.institution}</div>}</TableCell>
                        <TableCell className="whitespace-normal">
                            {g.checkins.length === 0 ? <StatusBadge status="belum_absen" label="Belum hadir" /> : g.checkins.map((c) => (
                                <div key={c.session} className="flex flex-wrap items-center gap-1.5"><StatusBadge status={c.status} /><span className="text-xs text-muted-foreground">{fmtDateTime(c.at)}{g.checkins.length > 1 && ` · ${c.session}`}</span></div>
                            ))}
                        </TableCell>
                        <TableCell><StatusBadge status={g.revoked_at ? 'revoked' : 'active'} label={g.purged ? 'Data dihapus' : g.revoked_at ? 'Dibatalkan' : 'Berlaku'} /></TableCell>
                        <TableCell>
                            <div className="flex flex-wrap justify-end gap-1.5">
                                {g.url && !g.revoked_at && !closed && <Button size="sm" variant="outline" aria-label={`Salin tautan QR ${g.name}`} onClick={() => copy(g.url!)}><Copy /> Salin tautan</Button>}
                                {g.url && <Button size="sm" variant="outline" render={<a href={g.url} target="_blank" rel="noreferrer" aria-label={`Buka QR ${g.name}`} />}><ExternalLink /> Buka</Button>}
                                {!g.purged && !closed && <ConfirmAction size="sm" href={`/kegiatan-tamu/${g.id}`} method="patch" data={{ revoked: g.revoked_at ? 0 : 1 }} title={g.revoked_at ? `Berlakukan lagi QR ${g.name}?` : `Batalkan QR ${g.name}?`}
                                    description={g.revoked_at ? 'QR lama bisa dipakai lagi.' : 'QR tidak bisa dipakai masuk. Kehadiran yang sudah tercatat tetap ada.'} confirmLabel={g.revoked_at ? 'Berlakukan' : 'Batalkan QR'}>{g.revoked_at ? 'Berlakukan' : 'Batalkan'}</ConfirmAction>}
                                {g.checkins.length === 0 && <ConfirmAction size="sm" destructive href={`/kegiatan-tamu/${g.id}`} method="delete" title={`Hapus ${g.name}?`} description="Data tamu dan QR-nya dihapus permanen." confirmLabel="Hapus">Hapus</ConfirmAction>}
                            </div>
                        </TableCell>
                    </TableRow>
                ))}
            </DataTable>
        </>
    );
}
