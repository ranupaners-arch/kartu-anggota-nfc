import { BellOff, BellRing } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
const send = (method: string, url: string, body?: unknown) => fetch(url, {
    method, body: body ? JSON.stringify(body) : undefined,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
});

/** Kunci publik VAPID (base64url) → Uint8Array untuk pushManager.subscribe. */
function keyBytes(base64: string) {
    const pad = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

type State = 'loading' | 'unsupported' | 'ios-install' | 'denied' | 'off' | 'on';

/** Aktifkan/matikan notifikasi push di perangkat ini (pengingat kegiatan & pengumuman). */
export function PushCard({ publicKey }: { publicKey: string | null }) {
    const [state, setState] = useState<State>('loading');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        (async () => {
            const ios = /iPhone|iPad|iPod/.test(navigator.userAgent);
            const standalone = window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone;
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                setState(ios && !standalone ? 'ios-install' : 'unsupported');
                return;
            }
            if (Notification.permission === 'denied') return setState('denied');
            const reg = await navigator.serviceWorker.register('/sw.js');
            const sub = await reg.pushManager.getSubscription();
            setState(sub ? 'on' : 'off');
        })().catch(() => setState('unsupported'));
    }, []);

    const enable = async () => {
        if (!publicKey) return;
        setBusy(true);
        try {
            if ((await Notification.requestPermission()) !== 'granted') return setState('denied');
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) });
            const res = await send('POST', '/saya/notifikasi', sub.toJSON());
            if (!res.ok) throw new Error();
            setState('on');
            await send('POST', '/saya/notifikasi/uji');
            toast.success('Notifikasi aktif di perangkat ini.');
        } catch {
            toast.error('Gagal mengaktifkan notifikasi. Coba lagi.');
        } finally {
            setBusy(false);
        }
    };

    const disable = async () => {
        setBusy(true);
        try {
            const sub = await (await navigator.serviceWorker.ready).pushManager.getSubscription();
            if (sub) {
                await send('DELETE', '/saya/notifikasi', { endpoint: sub.endpoint });
                await sub.unsubscribe();
            }
            setState('off');
        } finally {
            setBusy(false);
        }
    };

    if (!publicKey) return null; // fitur belum diaktifkan di server
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base"><BellRing className="size-4" /> Notifikasi</CardTitle>
                <CardDescription>Pengingat ±1 jam sebelum kegiatan dan pengumuman baru, langsung ke HP ini.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
                {state === 'loading' && <p className="text-muted-foreground">Memeriksa…</p>}
                {state === 'ios-install' && <p className="text-muted-foreground">Di iPhone, notifikasi hanya bisa setelah portal dipasang: Safari → <b>Bagikan → Tambahkan ke Layar Utama</b>, lalu buka dari ikon itu (iOS 16.4 atau lebih baru).</p>}
                {state === 'unsupported' && <p className="text-muted-foreground">Browser ini belum mendukung notifikasi push. Coba Chrome di Android, atau pasang portal di Layar Utama iPhone.</p>}
                {state === 'denied' && <p className="text-muted-foreground">Notifikasi diblokir untuk situs ini. Izinkan di pengaturan browser/HP, lalu muat ulang halaman.</p>}
                {state === 'off' && <div><Button onClick={enable} disabled={busy}><BellRing /> {busy ? 'Mengaktifkan…' : 'Aktifkan notifikasi'}</Button></div>}
                {state === 'on' && (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="rounded-md bg-emerald-500/10 px-2 py-0.5 font-medium text-emerald-700 dark:text-emerald-400">Aktif di perangkat ini</span>
                        <Button variant="outline" size="sm" onClick={disable} disabled={busy}><BellOff /> Matikan</Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
