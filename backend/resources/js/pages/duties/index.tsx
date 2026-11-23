import { Head, Link, useForm } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { DataTable } from '@/components/data-table';
import { EndDutyDialog } from '@/components/duty-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDate, fmtDateTime } from '@/lib/format';

type Duty = {
    id: number; type: string; label: string; division: string | null; ends_on: string | null; created_at: string; ended_at: string | null; end_reason: string | null;
    granted_by: string | null; term: string | null; user: { id: number; name: string; email: string } | null; member: { id: number; member_no: string } | null;
};

function Who({ d }: { d: Duty }) {
    return (
        <>
            {d.member ? <Link href={`/anggota/${d.member.id}`} className="font-medium hover:underline">{d.user?.name}</Link> : <span className="font-medium">{d.user?.name}</span>}
            <div className="text-xs text-muted-foreground">{d.member?.member_no}</div>
        </>
    );
}

/** Perpanjang tugas periode lalu: centang lalu "Perpanjang". */
function Previous({ items }: { items: Duty[] }) {
    const form = useForm<{ ids: number[] }>({ ids: items.map((d) => d.id) });
    const toggle = (id: number) => form.setData('ids', form.data.ids.includes(id) ? form.data.ids.filter((x) => x !== id) : [...form.data.ids, id]);
    return (
        <Card>
            <CardHeader>
                <CardTitle>Periode sebelumnya — belum diperpanjang</CardTitle>
                <CardDescription>Tugas berakhir otomatis saat periode berganti. Centang yang tetap bertugas, lalu perpanjang ke periode berjalan.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3">
                <DataTable head={['', 'Nama', 'Tugas', 'Periode']} count={items.length} empty="">
                    {items.map((d) => (
                        <TableRow key={d.id}>
                            <TableCell><input type="checkbox" aria-label={`Perpanjang ${d.user?.name}`} checked={form.data.ids.includes(d.id)} onChange={() => toggle(d.id)} /></TableCell>
                            <TableCell><Who d={d} /></TableCell>
                            <TableCell>{d.label}{d.division ? ` · ${d.division}` : ''}</TableCell>
                            <TableCell>{d.term}</TableCell>
                        </TableRow>
                    ))}
                </DataTable>
                <div>
                    <Button disabled={form.processing || form.data.ids.length === 0} onClick={() => form.post('/tugas/perpanjang', { preserveScroll: true })}>
                        <RotateCcw /> Perpanjang {form.data.ids.length} tugas
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

export default function Duties({ term, active, previous, history }: { term: string | null; active: Duty[]; previous: Duty[]; history: Duty[] }) {
    return (
        <AppLayout title="Petugas & Pengurus">
            <Head title="Petugas & Pengurus" />
            <p className="text-sm text-muted-foreground">
                Petugas Scan & Admin Divisi adalah <b>anggota yang diberi tugas</b> untuk periode {term ?? 'berjalan'}. Beri tugas dari halaman detail anggota
                (bagian <b>Tugas</b>). Mencabut tugas mengembalikan akunnya menjadi anggota biasa dan mengeluarkannya dari HP.
                Akun bersama tanpa data anggota (mis. HP sekretariat) tetap diatur di <Link href="/pengguna" className="underline">Pengguna & Peran</Link>.
            </p>
            {previous.length > 0 && <Previous items={previous} />}
            <h2 className="text-base font-semibold">Bertugas sekarang</h2>
            <DataTable head={['Nama', 'Tugas', 'Sejak', 'Sampai', 'Diberi oleh', 'Tindakan']} count={active.length} empty="Belum ada anggota yang bertugas di periode ini.">
                {active.map((d) => (
                    <TableRow key={d.id}>
                        <TableCell><Who d={d} /></TableCell>
                        <TableCell>{d.label}{d.division ? ` · ${d.division}` : ''}</TableCell>
                        <TableCell>{fmtDate(d.created_at)}</TableCell>
                        <TableCell>{d.ends_on ? fmtDate(d.ends_on) : 'Akhir periode'}</TableCell>
                        <TableCell>{d.granted_by ?? '—'}</TableCell>
                        <TableCell><EndDutyDialog dutyId={d.id} name={d.user?.name ?? ''} /></TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <h2 className="text-base font-semibold">Riwayat tugas berakhir</h2>
            <DataTable head={['Nama', 'Tugas', 'Periode', 'Berakhir', 'Alasan']} count={history.length} empty="Belum ada.">
                {history.map((d) => (
                    <TableRow key={d.id}>
                        <TableCell><Who d={d} /></TableCell>
                        <TableCell>{d.label}{d.division ? ` · ${d.division}` : ''}</TableCell>
                        <TableCell>{d.term}</TableCell>
                        <TableCell>{fmtDateTime(d.ended_at)}</TableCell>
                        <TableCell className="max-w-xs whitespace-normal">{d.end_reason ?? '—'}</TableCell>
                    </TableRow>
                ))}
            </DataTable>
        </AppLayout>
    );
}
