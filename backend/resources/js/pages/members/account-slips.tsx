import { Head, Link } from '@inertiajs/react';
import { Check, Copy, Printer, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { createPortal } from 'react-dom';
import { QRCodeSVG } from 'qrcode.react';
import { DataTable } from '@/components/data-table';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

type Created = { member_no: string; name: string; email: string; password: string };
type Skipped = { member_no: string; name: string; reason: string };
type Connect = { server: string; name: string | null; payload: string };

function Slips({ created, organization, connect }: { created: Created[]; organization: string | null; connect: Connect }) {
    return (
        <section aria-label="Lembar akun" className="grid gap-3 sm:grid-cols-2 print:grid-cols-2 print:gap-2">
            {created.map((c) => (
                <div key={c.member_no} className="grid break-inside-avoid gap-2 rounded-lg border border-dashed p-4 text-sm">
                    <div className="flex items-baseline justify-between gap-2">
                        <span className="font-semibold">{c.name}</span>
                        <span className="text-xs text-muted-foreground tabular-nums print:text-neutral-600">{c.member_no}</span>
                    </div>
                    <div className="text-xs text-muted-foreground print:text-neutral-600">App Kartu Anggota{organization ? ` · ${organization}` : ''}</div>
                    <div className="flex items-start gap-3">
                    <dl className="grid min-w-0 flex-1 grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1">
                        <dt className="text-muted-foreground print:text-neutral-600">Server</dt><dd className="font-mono text-xs break-all">{connect.server}</dd>
                        <dt className="text-muted-foreground print:text-neutral-600">Email</dt><dd className="font-mono break-all">{c.email}</dd>
                        <dt className="text-muted-foreground print:text-neutral-600">Password</dt><dd className="font-mono text-base font-semibold tracking-wide">{c.password}</dd>
                    </dl>
                    {/* QR penghubung: dipindai di app (Hubungkan organisasi → Scan QR) atau kamera HP. */}
                    <div className="shrink-0 rounded-md bg-white p-1.5" title="QR penghubung organisasi">
                        <QRCodeSVG value={connect.payload} size={84} marginSize={0} aria-label="QR penghubung organisasi" />
                    </div>
                    </div>
                    <p className="text-xs text-muted-foreground print:text-neutral-600">1. Pasang app Kartu Anggota, pindai QR ini untuk menghubungkan. 2. Masuk dengan email & password di atas. 3. Ganti password (wajib). Jangan bagikan lembar ini.</p>
                </div>
            ))}
        </section>
    );
}

/**
 * Hasil "Buat akun app": lembar per anggota berisi email & password sementara. Hanya tampil sekali
 * (dimuat ulang → hilang), jadi cetak/simpan sebagai PDF atau salin sekarang.
 */
export default function AccountSlips({ created, skipped, organization, connect }: { created: Created[]; skipped: Skipped[]; organization: string | null; connect: Connect }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        const rows = [['No. Anggota', 'Nama', 'Email', 'Password sementara'], ...created.map((c) => [c.member_no, c.name, c.email, c.password])];
        try {
            await navigator.clipboard.writeText(rows.map((r) => r.join('\t')).join('\n'));
            setCopied(true);
        } catch { /* clipboard ditolak */ }
    };

    return (
        <AppLayout title="Akun app anggota">
            <Head title="Akun app anggota" />
            {created.length > 0 && (
                <Alert className="print:hidden">
                    <TriangleAlert />
                    <AlertTitle>Simpan sekarang, password hanya tampil sekali</AlertTitle>
                    <AlertDescription>
                        Cetak lembar di bawah (atau <b>Simpan sebagai PDF</b> dari dialog cetak), atau salin ke spreadsheet, lalu bagikan ke masing-masing anggota.
                        Anggota wajib mengganti password saat pertama masuk. Bila lembar hilang, reset password dari <b>Pengguna & Peran</b>.
                    </AlertDescription>
                </Alert>
            )}
            <div className="flex flex-wrap items-center gap-2 print:hidden">
                <p className="mr-auto text-sm text-muted-foreground">{created.length} akun dibuat · {skipped.length} dilewati</p>
                {created.length > 0 && (
                    <>
                        <Button variant="outline" onClick={copy}>{copied ? <><Check /> Tersalin</> : <><Copy /> Salin tabel</>}</Button>
                        <Button onClick={() => window.print()}><Printer /> Cetak lembar</Button>
                    </>
                )}
                <Button variant="ghost" render={<Link href="/anggota" />}>Kembali ke Anggota</Button>
            </div>

            {created.length > 0 && <Slips created={created} organization={organization} connect={connect} />}
            {/* Salinan khusus cetak, langsung di <body> agar tidak ikut tata letak sidebar. */}
            {created.length > 0 && createPortal(
                <div data-print-root="" className="hidden bg-white p-4 text-black print:block">
                    <Slips created={created} organization={organization} connect={connect} />
                </div>,
                document.body,
            )}

            {skipped.length > 0 && (
                <Card className="print:hidden">
                    <CardHeader>
                        <CardTitle>Dilewati</CardTitle>
                        <CardDescription>Lengkapi email di detail anggota, lalu ulangi <b>Buat akun app</b> untuk mereka.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <DataTable head={['No. Anggota', 'Nama', 'Alasan']} count={skipped.length}>
                            {skipped.map((s) => (
                                <TableRow key={s.member_no}><TableCell className="tabular-nums">{s.member_no}</TableCell><TableCell>{s.name}</TableCell><TableCell>{s.reason}</TableCell></TableRow>
                            ))}
                        </DataTable>
                    </CardContent>
                </Card>
            )}
        </AppLayout>
    );
}
