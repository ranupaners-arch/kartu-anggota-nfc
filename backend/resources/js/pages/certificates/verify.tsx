import { Head } from '@inertiajs/react';
import { BadgeCheck, ShieldX } from 'lucide-react';
import { AuthShell } from '@/components/auth-shell';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { fmtDate } from '@/lib/format';

type Result = { valid: boolean; number: string; code: string; issued_at: string; event: string; date: string; organization: string | null; name: string | null } | null;

export default function VerifyCertificate({ result }: { result: Result }) {
    return (
        <AuthShell wide title="Cek keaslian sertifikat">
            <Head title="Cek keaslian sertifikat" />
            {!result ? (
                <Alert variant="destructive"><ShieldX /><AlertTitle>Tidak ditemukan</AlertTitle><AlertDescription>Kode ini tidak terdaftar. Sertifikat mungkin palsu atau kodenya salah ketik.</AlertDescription></Alert>
            ) : (
                <div className="grid gap-4">
                    {result.valid
                        ? <Alert><BadgeCheck className="text-emerald-600" /><AlertTitle>Sertifikat asli dan berlaku</AlertTitle><AlertDescription>Cocokkan isi di bawah dengan sertifikatnya.</AlertDescription></Alert>
                        : <Alert variant="destructive"><ShieldX /><AlertTitle>Sertifikat sudah dicabut</AlertTitle><AlertDescription>Sertifikat ini tidak berlaku lagi.</AlertDescription></Alert>}
                    <dl className="grid gap-3 text-sm">
                        {([['Nama', result.name], ['Kegiatan', result.event], ['Tanggal kegiatan', fmtDate(result.date)], ['Penyelenggara', result.organization], ['Nomor', result.number], ['Kode', result.code], ['Diterbitkan', fmtDate(result.issued_at)]] as [string, string | null][])
                            .filter(([, v]) => v).map(([k, v]) => <div key={k}><dt className="text-muted-foreground">{k}</dt><dd className="font-medium break-words">{v}</dd></div>)}
                    </dl>
                </div>
            )}
        </AuthShell>
    );
}
