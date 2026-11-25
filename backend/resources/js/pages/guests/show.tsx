import { Head } from '@inertiajs/react';
import { QrCode, TicketX } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { AuthShell } from '@/components/auth-shell';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { fmtDateTime } from '@/lib/format';

type Guest = { name: string; institution: string | null; revoked: boolean; event: string; location: string | null; starts_at: string; closed: boolean; organization: string | null; payload: string | null } | null;

export default function GuestPass({ guest: g }: { guest: Guest }) {
    return (
        <AuthShell title={g?.event ?? 'QR tamu'}>
            <Head title="QR tamu" />
            {!g ? (
                <Alert variant="destructive"><TicketX /><AlertTitle>Tidak ditemukan</AlertTitle><AlertDescription>Tautan ini tidak berlaku. Hubungi panitia.</AlertDescription></Alert>
            ) : (
                <div className="grid gap-4">
                    {g.payload ? (
                        <>
                            <div className="mx-auto rounded-xl border bg-white p-4"><QRCodeSVG value={g.payload} size={232} marginSize={0} level="M" aria-label={`QR tamu ${g.name}`} /></div>
                            <Alert><QrCode /><AlertTitle>Tunjukkan ke petugas</AlertTitle><AlertDescription>QR ini hanya untuk Anda. Jangan dibagikan.</AlertDescription></Alert>
                        </>
                    ) : (
                        <Alert variant="destructive"><TicketX /><AlertTitle>{g.revoked ? 'QR dibatalkan' : 'Kegiatan sudah selesai'}</AlertTitle><AlertDescription>{g.revoked ? 'Hubungi panitia bila ini keliru.' : 'QR tidak bisa dipakai lagi.'}</AlertDescription></Alert>
                    )}
                    <dl className="grid gap-3 text-sm">
                        {([['Tamu', g.name], ['Instansi', g.institution], ['Waktu', fmtDateTime(g.starts_at)], ['Lokasi', g.location], ['Penyelenggara', g.organization]] as [string, string | null][])
                            .filter(([, v]) => v).map(([k, v]) => <div key={k}><dt className="text-muted-foreground">{k}</dt><dd className="font-medium break-words">{v}</dd></div>)}
                    </dl>
                </div>
            )}
        </AuthShell>
    );
}
