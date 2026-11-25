import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CalendarPlus, Check, Copy, KeyRound, LogOut, Wallet } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { FormField } from '@/components/form-field';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import PortalLayout from '@/layouts/portal-layout';
import { Copyright } from '@/components/copyright';
import { PushCard } from '@/components/push-card';
import type { SharedProps } from '@/types';

type Profile = { name: string; member_no: string; division: string | null; organization: string | null; email: string };

function CalendarLink({ url }: { url: string | null }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        if (!url) return;
        try { await navigator.clipboard.writeText(url); setCopied(true); } catch { /* salin manual dari kotak */ }
    };
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base"><CalendarPlus className="size-4" /> Langganan jadwal</CardTitle>
                <CardDescription>Kegiatan Anda muncul otomatis di kalender HP.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
                {url ? (
                    <>
                        <div className="flex gap-2">
                            <Input readOnly value={url} aria-label="Tautan langganan jadwal" className="font-mono text-xs" onFocus={(e) => e.currentTarget.select()} />
                            <Button variant="outline" onClick={copy}>{copied ? <><Check /> Tersalin</> : <><Copy /> Salin</>}</Button>
                        </div>
                        <p className="text-muted-foreground">iPhone: <b>Pengaturan → Kalender → Akun → Tambah Akun → Lainnya → Tambah Kalender Langganan</b>, tempel tautan. Google Calendar: <b>Setelan → Tambahkan kalender → Dari URL</b> (di browser).</p>
                        <div><Button variant="link" className="h-auto px-0" onClick={() => router.post('/saya/kalender', {}, { preserveScroll: true })}>Buat tautan baru (yang lama mati)</Button></div>
                    </>
                ) : (
                    <div><Button onClick={() => router.post('/saya/kalender', {}, { preserveScroll: true })}><CalendarPlus /> Buat tautan langganan</Button></div>
                )}
            </CardContent>
        </Card>
    );
}

export default function PortalAccount({ profile, mustChangePassword, calendarUrl, push }: { profile: Profile; mustChangePassword: boolean; calendarUrl: string | null; push: { publicKey: string | null; devices: number } }) {
    const { auth } = usePage<SharedProps>().props;
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/saya/password', { preserveScroll: true, onFinish: () => form.reset() });
    };
    return (
        <PortalLayout title="Akun">
            <Head title="Akun" />
            {mustChangePassword && (
                <Alert>
                    <KeyRound />
                    <AlertTitle>Buat password baru dulu</AlertTitle>
                    <AlertDescription>Ganti password sementara Anda dulu.</AlertDescription>
                </Alert>
            )}
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">{profile.name}</CardTitle>
                    <CardDescription>{[profile.member_no, profile.division, profile.email].filter(Boolean).join(' · ')}</CardDescription>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader><CardTitle className="text-base">Ganti password</CardTitle><CardDescription>Minimal 10 karakter, berisi huruf dan angka.</CardDescription></CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="grid gap-4">
                        <FormField label={mustChangePassword ? 'Password sementara' : 'Password sekarang'} error={form.errors.current_password}>
                            <Input type="password" autoComplete="current-password" required value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)} />
                        </FormField>
                        <FormField label="Password baru" error={form.errors.password}>
                            <Input type="password" autoComplete="new-password" required value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                        </FormField>
                        <FormField label="Ulangi password baru">
                            <Input type="password" autoComplete="new-password" required value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} />
                        </FormField>
                        <Button type="submit" disabled={form.processing}>{form.processing ? 'Menyimpan…' : 'Simpan password'}</Button>
                    </form>
                </CardContent>
            </Card>
            {!mustChangePassword && <PushCard publicKey={push.publicKey} />}
            {!mustChangePassword && auth?.canViewDues && <Button variant="outline" render={<Link href="/iuran" />}><Wallet /> Kelola iuran</Button>}
            {!mustChangePassword && <CalendarLink url={calendarUrl} />}
            <Button variant="outline" onClick={() => router.post('/keluar')}><LogOut /> Keluar</Button>
            <Copyright className="text-center" />
        </PortalLayout>
    );
}
