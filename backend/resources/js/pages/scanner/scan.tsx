import { Head } from '@inertiajs/react';
import { Camera, CameraOff, RefreshCw, UserRound } from 'lucide-react';
import QrScanner from 'qr-scanner';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import ScannerLayout from '@/layouts/scanner-layout';
import { fmtTime } from '@/lib/format';
import { cn } from '@/lib/utils';

type Session = { id: number; title: string; checkin_opens_at: string; late_after: string; checkin_closes_at: string };
type Result = {
    status: 'ok' | 'duplicate' | 'invalid' | 'blocked' | 'not_target' | 'outside_window' | 'clock_drift' | string;
    attendance_status: string | null; message: string;
    member: { id: number; member_no: string; name: string; division: string | null; photo_url: string | null } | null;
};
type Recent = Result & { at: number; key: number };

/** Warna & label hasil: hijau berhasil, kuning sudah tercatat, merah ditolak. */
function tone(r: Result) {
    if (r.status === 'ok') return { cls: 'border-emerald-500 bg-emerald-500/10', title: r.attendance_status === 'telat' ? 'Telat' : 'Hadir', text: 'text-emerald-700 dark:text-emerald-400' };
    if (r.status === 'duplicate') return { cls: 'border-amber-500 bg-amber-500/10', title: 'Sudah tercatat', text: 'text-amber-700 dark:text-amber-400' };
    return { cls: 'border-destructive bg-destructive/10', title: 'Ditolak', text: 'text-destructive' };
}

/** Bunyi pendek (berhasil: nada tinggi; gagal: nada rendah) + getar bila didukung. */
function feedback(ok: boolean) {
    try {
        navigator.vibrate?.(ok ? 80 : [60, 60, 60]);
        const ctx = new AudioContext();
        const osc = ctx.createOscillator();
        osc.frequency.value = ok ? 1320 : 330;
        osc.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + (ok ? 0.12 : 0.3));
        osc.onended = () => ctx.close();
    } catch { /* tanpa suara */ }
}

/** Token CSRF Laravel dari cookie XSRF-TOKEN (untuk fetch JSON di luar Inertia). */
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

export default function ScannerScan({ event, session, multiSession }: { event: { id: number; title: string; location: string | null }; session: Session; multiSession: boolean }) {
    const video = useRef<HTMLVideoElement>(null);
    const scanner = useRef<QrScanner | null>(null);
    const lastPayload = useRef<{ text: string; at: number } | null>(null);
    const busy = useRef(false);
    const [running, setRunning] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<Result | null>(null);
    const [recent, setRecent] = useState<Recent[]>([]);
    const [cameras, setCameras] = useState<QrScanner.Camera[]>([]);
    const [camIndex, setCamIndex] = useState(0);

    const submit = useCallback(async (payload: string) => {
        // Abaikan QR yang sama dalam 4 detik (kamera membaca berkali-kali per detik).
        const now = Date.now();
        if (busy.current || (lastPayload.current?.text === payload && now - lastPayload.current.at < 4000)) return;
        busy.current = true;
        lastPayload.current = { text: payload, at: now };
        try {
            const res = await fetch(`/pindai/${event.id}/scan`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ payload }),
            });
            const data: Result = res.ok ? await res.json() : { status: 'error', attendance_status: null, member: null, message: res.status === 419 ? 'Sesi berakhir — muat ulang halaman.' : 'Gagal menghubungi server.' };
            setResult(data);
            setRecent((r) => [{ ...data, at: now, key: now }, ...r].slice(0, 8));
            feedback(data.status === 'ok');
        } catch {
            setResult({ status: 'error', attendance_status: null, member: null, message: 'Tidak ada koneksi internet.' });
            feedback(false);
        } finally {
            busy.current = false;
        }
    }, [event.id]);

    const start = useCallback(async () => {
        setError(null);
        if (!video.current) return;
        try {
            scanner.current?.destroy();
            const s = new QrScanner(video.current, (r) => submit(r.data), {
                preferredCamera: cameras[camIndex]?.id ?? 'environment',
                highlightScanRegion: true, highlightCodeOutline: true, maxScansPerSecond: 8, returnDetailedScanResult: true,
            });
            scanner.current = s;
            await s.start();
            setRunning(true);
            if (!cameras.length) setCameras(await QrScanner.listCameras(true));
        } catch {
            setRunning(false);
            setError(window.isSecureContext
                ? 'Kamera tidak bisa dibuka. Izinkan akses kamera untuk situs ini di pengaturan browser, lalu coba lagi.'
                : 'Kamera hanya bisa dipakai lewat alamat https://.');
        }
    }, [cameras, camIndex, submit]);

    useEffect(() => {
        start();
        return () => scanner.current?.destroy();
    }, [camIndex]); // eslint-disable-line react-hooks/exhaustive-deps

    const stop = () => { scanner.current?.stop(); setRunning(false); };
    const t = result ? tone(result) : null;

    return (
        <ScannerLayout title={event.title} subtitle={[multiSession && session.title, `check-in ${fmtTime(session.checkin_opens_at)}–${fmtTime(session.checkin_closes_at)}`, `telat setelah ${fmtTime(session.late_after)}`].filter(Boolean).join(' · ')} back="/pindai">
            <Head title={`Pindai · ${event.title}`} />
            <div className="relative overflow-hidden rounded-2xl bg-black">
                {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
                <video ref={video} className="aspect-square w-full object-cover" playsInline muted aria-label="Tampilan kamera untuk memindai QR" />
                {!running && (
                    <div className="absolute inset-0 grid place-items-center p-6 text-center text-sm text-white/80">
                        {error ? null : 'Membuka kamera…'}
                    </div>
                )}
            </div>
            {error && <Alert variant="destructive"><CameraOff /><AlertTitle>Kamera</AlertTitle><AlertDescription>{error}</AlertDescription></Alert>}
            <div className="flex gap-2">
                {running
                    ? <Button variant="outline" className="flex-1" onClick={stop}><CameraOff /> Jeda kamera</Button>
                    : <Button className="flex-1" onClick={start}><Camera /> Mulai pindai</Button>}
                {cameras.length > 1 && (
                    <Button variant="outline" onClick={() => setCamIndex((i) => (i + 1) % cameras.length)} aria-label="Ganti kamera"><RefreshCw /> Ganti kamera</Button>
                )}
            </div>

            <div role="status" aria-live="assertive" className="min-h-24">
                {result && t && (
                    <div className={cn('flex items-center gap-3 rounded-xl border-2 p-3', t.cls)}>
                        {result.member?.photo_url
                            ? <img src={result.member.photo_url} alt={`Foto ${result.member.name}`} className="size-16 shrink-0 rounded-lg object-cover" />
                            : <div className="flex size-16 shrink-0 items-center justify-center rounded-lg bg-muted"><UserRound className="size-8 text-muted-foreground" /></div>}
                        <div className="min-w-0 flex-1">
                            <div className={cn('text-lg font-bold', t.text)}>{t.title}</div>
                            {result.member && <div className="truncate font-medium">{result.member.name}</div>}
                            <div className="truncate text-sm text-muted-foreground">
                                {[result.member?.member_no, result.member?.division].filter(Boolean).join(' · ')}
                                {result.status !== 'ok' && <> {result.member ? '· ' : ''}{result.message}</>}
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {recent.length > 0 && (
                <section aria-label="Pindaian terakhir" className="grid gap-1">
                    <h2 className="text-sm font-medium text-muted-foreground">Pindaian terakhir</h2>
                    <ul className="divide-y rounded-xl border">
                        {recent.map((r) => (
                            <li key={r.key} className="flex items-center gap-2 px-3 py-2 text-sm">
                                <span className="min-w-0 flex-1 truncate">{r.member?.name ?? r.message}</span>
                                <span className={cn('shrink-0 text-xs font-medium', tone(r).text)}>{tone(r).title}</span>
                                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">{new Date(r.at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </ScannerLayout>
    );
}
