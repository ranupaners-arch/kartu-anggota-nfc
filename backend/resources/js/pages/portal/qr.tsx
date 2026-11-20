import { Head, router, usePoll } from '@inertiajs/react';
import { QRCodeSVG } from 'qrcode.react';
import { useEffect, useState } from 'react';
import { MemberCard, type MemberCardInfo } from '@/components/member-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent } from '@/components/ui/card';
import PortalLayout from '@/layouts/portal-layout';

type Profile = { name: string; member_no: string; division: string | null; organization: string | null };
type Qr = { payload: string; expires_in: number; generated_at: string };

/** Jaga layar tetap menyala selama QR ditampilkan (bila browser mendukung), pasang ulang saat kembali ke tab. */
function useWakeLock() {
    useEffect(() => {
        if (!('wakeLock' in navigator)) return;
        let lock: WakeLockSentinel | null = null;
        const acquire = async () => {
            try {
                if (document.visibilityState === 'visible') lock = await navigator.wakeLock.request('screen');
            } catch { /* ditolak (mis. hemat daya) — QR tetap tampil */ }
        };
        const onVisible = () => { if (document.visibilityState === 'visible') acquire(); };
        acquire();
        document.addEventListener('visibilitychange', onVisible);
        return () => {
            document.removeEventListener('visibilitychange', onVisible);
            lock?.release().catch(() => {});
        };
    }, []);
}

/** QR Saya: cadangan saat kartu tertinggal. Server merotasi QR tiap menit; halaman mengambil yang baru tiap 30 detik. */
export default function PortalQr({ profile, qr, card }: { profile: Profile; qr: Qr | null; card: MemberCardInfo }) {
    // QR berganti tiap menit; kartu ikut diperbarui agar pengesahan langsung terlihat.
    usePoll(30_000, { only: ['qr', 'card'] }, { keepAlive: true });
    if (!qr) return <CardOnly card={card} />;
    return <ActiveQr profile={profile} qr={qr} card={card} />;
}

/** Belum e-profil / menunggu pengesahan / tidak aktif: kartu + penjelasan, tanpa QR. */
function CardOnly({ card }: { card: MemberCardInfo }) {
    return (
        <PortalLayout title="Kartu Saya">
            <Head title="Kartu Saya" />
            <MemberCard info={card} onRegister={() => router.visit('/saya/e-profil')} />
            {card.state === 'none' && <Alert><AlertTitle>Belum terdaftar sebagai anggota</AlertTitle><AlertDescription>Ketuk kartu di atas untuk mengisi e-profil.</AlertDescription></Alert>}
            {card.state === 'pending' && <Alert><AlertTitle>Menunggu pengesahan pusat</AlertTitle><AlertDescription>E-profil Anda sedang diperiksa pengurus. Kartu aktif otomatis setelah disahkan.</AlertDescription></Alert>}
            {card.state === 'inactive' && <Alert><AlertTitle>{card.status === 'alumni' ? 'Status: alumni' : 'Keanggotaan tidak aktif'}</AlertTitle><AlertDescription>Kartu tidak bisa dipakai untuk absensi periode ini.</AlertDescription></Alert>}
        </PortalLayout>
    );
}

function ActiveQr({ profile, qr, card }: { profile: Profile; qr: Qr; card: MemberCardInfo }) {
    useWakeLock();
    const [age, setAge] = useState(0);
    useEffect(() => {
        const start = Date.now();
        setAge(0);
        const t = setInterval(() => setAge(Math.floor((Date.now() - start) / 1000)), 1000);
        return () => clearInterval(t);
    }, [qr.payload]);
    const stale = age > qr.expires_in;

    return (
        <PortalLayout title="Kartu Saya">
            <Head title="Kartu Saya" />
            <MemberCard info={card} />
            <Card>
                <CardContent className="grid justify-items-center gap-4 py-2 text-center">
                    <div className={`rounded-2xl bg-white p-4 transition-opacity ${stale ? 'opacity-30' : ''}`}>
                        <QRCodeSVG value={qr.payload} size={240} marginSize={0} level="M" aria-label={`QR kehadiran ${profile.name}`} />
                    </div>
                    <div className="grid gap-0.5">
                        <div className="text-lg font-semibold">{profile.name}</div>
                        <div className="text-sm text-muted-foreground tabular-nums">{[profile.member_no, profile.division].filter(Boolean).join(' · ')}</div>
                    </div>
                    <p role="status" className="text-sm text-muted-foreground">
                        {stale ? 'QR kedaluwarsa — periksa koneksi internet, halaman akan memperbarui otomatis.' : 'Tunjukkan ke petugas untuk dipindai. QR berganti otomatis tiap menit.'}
                    </p>
                </CardContent>
            </Card>
            <p className="text-center text-xs text-muted-foreground">Naikkan kecerahan layar agar mudah dipindai. Jangan bagikan tangkapan layar QR ini.</p>
        </PortalLayout>
    );
}
