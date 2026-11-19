import { Head, Link, router, useForm } from '@inertiajs/react';
import { Copy, ShieldAlert } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { type FormEvent, useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/data-table';
import { FormField } from '@/components/form-field';
import { PageTabs } from '@/components/page-tabs';
import { StatusBadge } from '@/components/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDate, fmtDateTime } from '@/lib/format';

type Term = { id: number; name: string; starts_on: string; ends_on: string; is_current: boolean };
type Div = { id: number; name: string; memberships_count: number };
type Connect = { server: string; name: string; payload: string; https: boolean };

/** QR untuk menghubungkan app petugas ke server organisasi ini (model self-hosted). */
function AppConnect({ connect }: { connect: Connect }) {
    return (
        <div className="grid max-w-3xl gap-4">
            {!connect.https && (
                <Alert>
                    <ShieldAlert />
                    <AlertTitle>Server belum memakai HTTPS</AlertTitle>
                    <AlertDescription>APK uji bisa terhubung, tetapi APK yang dipublish hanya mau terhubung ke alamat https://.</AlertDescription>
                </Alert>
            )}
            <Card>
                <CardHeader>
                    <CardTitle>Hubungkan app petugas</CardTitle>
                    <CardDescription>Pindai kode ini dengan kamera HP yang sudah terpasang app, atau di app pilih “Scan QR dari dashboard”. App akan terhubung ke server {connect.name}.</CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-6 sm:flex-row sm:items-center">
                    <div className="rounded-xl border bg-white p-4"><QRCodeSVG value={connect.payload} size={200} marginSize={0} /></div>
                    <div className="grid gap-3 text-sm">
                        <div>
                            <div className="text-muted-foreground">Alamat server (bila diketik manual)</div>
                            <div className="flex items-center gap-2">
                                <code className="rounded bg-muted px-2 py-1">{connect.server}</code>
                                <Button size="icon-sm" variant="ghost" aria-label="Salin alamat" onClick={() => navigator.clipboard?.writeText(connect.server)}><Copy /></Button>
                            </div>
                        </div>
                        <div><div className="text-muted-foreground">Nama server</div>{connect.name}</div>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}

function Organization({ org }: { org: { name: string; slug: string } }) {
    const form = useForm({ name: org.name });
    return (
        <Card className="max-w-xl">
            <CardHeader><CardTitle>Profil organisasi</CardTitle><CardDescription>Kode: {org.slug}</CardDescription></CardHeader>
            <CardContent>
                <form onSubmit={(e) => { e.preventDefault(); form.put('/pengaturan/organisasi', { preserveScroll: true }); }} className="flex items-end gap-2">
                    <div className="flex-1"><FormField label="Nama organisasi" error={form.errors.name}><Input required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></FormField></div>
                    <Button type="submit" disabled={form.processing}>Simpan</Button>
                </form>
            </CardContent>
        </Card>
    );
}

function Terms({ terms }: { terms: Term[] }) {
    const form = useForm({ name: '', starts_on: '', ends_on: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/pengaturan/periode', { preserveScroll: true, onSuccess: () => form.reset() }); };
    return (
        <>
            <DataTable head={['Periode', 'Mulai', 'Selesai', 'Status', '']} count={terms.length}>
                {terms.map((t) => (
                    <TableRow key={t.id}>
                        <TableCell className="font-medium">{t.name}</TableCell><TableCell>{fmtDate(t.starts_on)}</TableCell><TableCell>{fmtDate(t.ends_on)}</TableCell>
                        <TableCell>{t.is_current && <StatusBadge status="active" label="Berjalan" />}</TableCell>
                        <TableCell className="text-right">{!t.is_current && (
                            <ConfirmAction size="sm" title={`Jadikan ${t.name} periode berjalan?`} description="Keanggotaan dan rekap baru mengikuti periode ini. Data periode lama tidak diubah." href={`/pengaturan/periode/${t.id}/berjalan`} confirmLabel="Jadikan berjalan">
                                Jadikan berjalan
                            </ConfirmAction>
                        )}</TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <Card>
                <CardHeader><CardTitle>Tambah periode</CardTitle></CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-4 sm:items-end">
                        <FormField label="Nama periode" error={form.errors.name}><Input placeholder="2027/2028" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></FormField>
                        <FormField label="Mulai" error={form.errors.starts_on}><Input type="date" required value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} /></FormField>
                        <FormField label="Selesai" error={form.errors.ends_on}><Input type="date" required value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} /></FormField>
                        <Button type="submit" disabled={form.processing}>Tambah Periode</Button>
                    </form>
                </CardContent>
            </Card>
        </>
    );
}

function DivisionRow({ d }: { d: Div }) {
    const [name, setName] = useState(d.name);
    return (
        <TableRow>
            <TableCell><Input className="h-8 max-w-xs" value={name} onChange={(e) => setName(e.target.value)} /></TableCell>
            <TableCell className="tabular-nums">{d.memberships_count}</TableCell>
            <TableCell className="text-right">
                <Button size="sm" variant="outline" disabled={name === d.name || !name} onClick={() => router.put(`/pengaturan/divisi/${d.id}`, { name }, { preserveScroll: true })}>Simpan nama</Button>
            </TableCell>
        </TableRow>
    );
}

function Divisions({ divisions }: { divisions: Div[] }) {
    const form = useForm({ name: '' });
    return (
        <>
            <DataTable head={['Divisi', 'Keanggotaan', '']} count={divisions.length}>
                {divisions.map((d) => <DivisionRow key={d.id} d={d} />)}
            </DataTable>
            <Card className="max-w-xl">
                <CardHeader><CardTitle>Tambah divisi</CardTitle></CardHeader>
                <CardContent>
                    <form onSubmit={(e) => { e.preventDefault(); form.post('/pengaturan/divisi', { preserveScroll: true, onSuccess: () => form.reset() }); }} className="flex items-end gap-2">
                        <div className="flex-1"><FormField label="Nama divisi" error={form.errors.name}><Input required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></FormField></div>
                        <Button type="submit" disabled={form.processing}>Tambah</Button>
                    </form>
                </CardContent>
            </Card>
        </>
    );
}

type Privacy = { excuseDays: number; memberMonths: number | null; exportDays: number; pending: { excuses: number; members: number; exports: number } };

/** Retensi data (PRD §13): dijalankan otomatis tiap hari pukul 02.30. */
function PrivacySettings({ privacy }: { privacy: Privacy }) {
    const form = useForm({ retention_excuse_days: String(privacy.excuseDays), retention_member_months: privacy.memberMonths ? String(privacy.memberMonths) : '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, retention_member_months: d.retention_member_months || null }));
        form.put('/pengaturan/privasi', { preserveScroll: true });
    };
    const p = privacy.pending;
    return (
        <>
            <Card className="max-w-2xl">
                <CardHeader>
                    <CardTitle>Retensi data</CardTitle>
                    <CardDescription>Data pribadi yang sudah tidak diperlukan dihapus otomatis tiap hari pukul 02.30. Catatan kehadiran (rekap) tidak ikut terhapus.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="grid gap-4">
                        <FormField label="Alasan & lampiran izin dihapus setelah periode berakhir (hari)" hint="Minimal 30 hari." error={form.errors.retention_excuse_days}>
                            <Input type="number" min={30} max={3650} required className="w-32" value={form.data.retention_excuse_days} onChange={(e) => form.setData('retention_excuse_days', e.target.value)} />
                        </FormField>
                        <FormField label="Data pribadi anggota dihapus setelah periode terakhirnya berakhir (bulan)" hint="Kosongkan untuk mematikan. Berlaku untuk anggota yang tidak terdaftar di periode berjalan." error={form.errors.retention_member_months}>
                            <Input type="number" min={6} max={120} className="w-32" placeholder="mati" value={form.data.retention_member_months} onChange={(e) => form.setData('retention_member_months', e.target.value)} />
                        </FormField>
                        <p className="text-sm text-muted-foreground">Berkas ekspor (berisi nama anggota) dihapus setelah {privacy.exportDays} hari.</p>
                        <div><Button type="submit" disabled={form.processing}>Simpan</Button></div>
                    </form>
                </CardContent>
            </Card>
            <Card className="max-w-2xl" size="sm">
                <CardHeader>
                    <CardTitle>Akan dihapus pada jalan berikutnya</CardTitle>
                    <CardDescription>Menurut pengaturan yang tersimpan saat ini.</CardDescription>
                </CardHeader>
                <CardContent className="grid grid-cols-3 gap-4 text-sm">
                    <div><div className="text-2xl font-semibold tabular-nums">{p.excuses}</div><div className="text-muted-foreground">pengajuan izin</div></div>
                    <div><div className="text-2xl font-semibold tabular-nums">{p.members}</div><div className="text-muted-foreground">anggota</div></div>
                    <div><div className="text-2xl font-semibold tabular-nums">{p.exports}</div><div className="text-muted-foreground">berkas ekspor</div></div>
                </CardContent>
            </Card>
        </>
    );
}

type Check = { status: 'ok' | 'warn' | 'fail'; message: string; ms?: number };
type Health = { status: Check['status']; checks: Record<string, Check>; checked_at: string; sentry: boolean; healthTokenSet: boolean; healthUrl: string };
const checkLabels: Record<string, string> = { database: 'Database', cache: 'Cache (Redis)', storage: 'Penyimpanan berkas', queue: 'Queue worker', scheduler: 'Scheduler' };
const statusTone: Record<Check['status'], string> = {
    ok: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400', warn: 'bg-amber-500/10 text-amber-700 dark:text-amber-400', fail: 'bg-destructive/10 text-destructive',
};
const statusText: Record<Check['status'], string> = { ok: 'Normal', warn: 'Perlu dicek', fail: 'Gagal' };

/** Status sistem (sama dengan GET /api/health dan `php artisan app:health`). */
function SystemHealth({ health }: { health: Health }) {
    return (
        <>
            <Card className="max-w-3xl">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        Status sistem <span className={`rounded-md px-2 py-0.5 text-sm font-medium ${statusTone[health.status]}`}>{statusText[health.status]}</span>
                    </CardTitle>
                    <CardDescription>Diperiksa {fmtDateTime(health.checked_at)}. Queue worker & scheduler dinilai dari detak tiap menit.</CardDescription>
                </CardHeader>
                <CardContent className="grid gap-2">
                    {Object.entries(health.checks).map(([name, c]) => (
                        <div key={name} className="flex flex-wrap items-center gap-3 rounded-lg border p-3 text-sm">
                            <span className={`w-24 rounded-md px-2 py-0.5 text-center text-xs font-medium ${statusTone[c.status]}`}>{statusText[c.status]}</span>
                            <span className="w-40 font-medium">{checkLabels[name] ?? name}</span>
                            <span className="flex-1 text-muted-foreground">{c.message}</span>
                            {c.ms !== undefined && <span className="tabular-nums text-xs text-muted-foreground">{c.ms} ms</span>}
                        </div>
                    ))}
                    <div><Button variant="outline" size="sm" render={<Link href="/pengaturan?tab=sistem" preserveScroll />}>Periksa ulang</Button></div>
                </CardContent>
            </Card>
            <Card className="max-w-3xl" size="sm">
                <CardHeader><CardTitle>Pemantauan</CardTitle></CardHeader>
                <CardContent className="grid gap-1 text-sm">
                    <p>Pelacakan error (Sentry): <b>{health.sentry ? 'aktif' : 'tidak aktif'}</b>{!health.sentry && ' — isi SENTRY_LARAVEL_DSN di .env server untuk mengaktifkan.'}</p>
                    <p>Pemantau uptime: <code className="break-all">{health.healthUrl}</code> (HTTP 503 bila ada yang gagal){health.healthTokenSet ? '; detail dengan header X-Health-Token.' : '.'}</p>
                </CardContent>
            </Card>
        </>
    );
}

export default function Settings({ tab, org, terms, divisions, connect, privacy, health }: { tab: string; org: { name: string; slug: string }; terms: Term[]; divisions: Div[]; connect: Connect; privacy: Privacy | null; health: Health | null }) {
    return (
        <AppLayout title="Pengaturan">
            <Head title="Pengaturan" />
            <PageTabs tabs={{ organisasi: 'Organisasi', periode: 'Periode', divisi: 'Divisi', aplikasi: 'Aplikasi', privasi: 'Privasi', sistem: 'Sistem' }} current={tab} />
            {tab === 'organisasi' && <Organization org={org} />}
            {tab === 'periode' && <Terms terms={terms} />}
            {tab === 'divisi' && <Divisions divisions={divisions} />}
            {tab === 'aplikasi' && <AppConnect connect={connect} />}
            {tab === 'privasi' && privacy && <PrivacySettings privacy={privacy} />}
            {tab === 'sistem' && health && <SystemHealth health={health} />}
        </AppLayout>
    );
}
