import { Head, router, useForm } from '@inertiajs/react';
import { Copy, ShieldCheck } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { fmtDateTime, roleLabel } from '@/lib/format';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

type TwoFactor = {
    enabled: boolean; confirmedAt: string | null; recoveryRemaining: number;
    setup: { otpauthUrl: string; secret: string } | null; recoveryCodes: string[] | null;
};
interface Props { user: { name: string; email: string; role: string; member: { member_no: string; name: string } | null }; twoFactor: TwoFactor }

/** Aktifkan / konfirmasi / nonaktifkan 2FA (TOTP). */
function TwoFactorCard({ tf }: { tf: TwoFactor }) {
    const confirm = useForm({ code: '' });
    const disable = useForm({ password: '' });

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">Keamanan (2FA){tf.enabled && <ShieldCheck className="size-4 text-emerald-600 dark:text-emerald-400" />}</CardTitle>
                <CardDescription>Verifikasi dua langkah dengan aplikasi authenticator (Google Authenticator, Authy, 2FAS, dll.).</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
                {tf.recoveryCodes && (
                    <Alert>
                        <AlertTitle>Simpan kode cadangan ini sekarang</AlertTitle>
                        <AlertDescription>
                            <p>Dipakai bila HP hilang. Setiap kode sekali pakai, dan tidak akan ditampilkan lagi.</p>
                            <div className="mt-2 grid grid-cols-2 gap-1 font-mono text-sm">{tf.recoveryCodes.map((c) => <code key={c}>{c}</code>)}</div>
                            <Button size="sm" variant="outline" className="mt-2" onClick={() => navigator.clipboard?.writeText(tf.recoveryCodes!.join('\n'))}><Copy /> Salin</Button>
                        </AlertDescription>
                    </Alert>
                )}
                {tf.enabled ? (
                    <>
                        <p className="text-sm">2FA <b>aktif</b> sejak {fmtDateTime(tf.confirmedAt)} · {tf.recoveryRemaining} kode cadangan tersisa.</p>
                        <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); disable.delete('/akun/2fa', { preserveScroll: true, onSuccess: () => disable.reset() }); }}>
                            <FormField label="Password untuk menonaktifkan" error={disable.errors.password}>
                                <Input type="password" autoComplete="current-password" value={disable.data.password} onChange={(e) => disable.setData('password', e.target.value)} />
                            </FormField>
                            <Button type="submit" variant="destructive" disabled={disable.processing}>Nonaktifkan 2FA</Button>
                        </form>
                    </>
                ) : tf.setup ? (
                    <div className="grid gap-4 sm:grid-cols-[auto_1fr] sm:items-start">
                        <div className="rounded-xl border bg-white p-3"><QRCodeSVG value={tf.setup.otpauthUrl} size={176} marginSize={0} /></div>
                        <div className="grid gap-3 text-sm">
                            <ol className="list-decimal space-y-1 pl-4">
                                <li>Buka aplikasi authenticator, tambah akun, lalu scan QR ini.</li>
                                <li>Masukkan kode 6 digit yang muncul di aplikasi.</li>
                            </ol>
                            <div className="text-muted-foreground">Tidak bisa scan? Ketik kunci: <code className="break-all">{tf.setup.secret}</code></div>
                            <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); confirm.post('/akun/2fa/konfirmasi', { preserveScroll: true, onError: () => confirm.reset() }); }}>
                                <FormField label="Kode 6 digit" error={confirm.errors.code}>
                                    <Input inputMode="numeric" maxLength={6} autoComplete="one-time-code" className="w-36 tracking-[0.3em]" value={confirm.data.code} onChange={(e) => confirm.setData('code', e.target.value)} />
                                </FormField>
                                <Button type="submit" disabled={confirm.processing}>Konfirmasi</Button>
                            </form>
                        </div>
                    </div>
                ) : (
                    <div><Button onClick={() => router.post('/akun/2fa', {}, { preserveScroll: true })}><ShieldCheck /> Aktifkan 2FA</Button></div>
                )}
            </CardContent>
        </Card>
    );
}

export default function Account({ user, twoFactor }: Props) {
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/akun/password', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <AppLayout title="Akun Saya">
            <Head title="Akun Saya" />
            <div className="grid max-w-3xl gap-4">
                <Card>
                    <CardHeader><CardTitle>{user.name}</CardTitle><CardDescription>{user.email}</CardDescription></CardHeader>
                    <CardContent className="text-sm text-muted-foreground">
                        Peran: {roleLabel[user.role]}{user.member && ` · Anggota ${user.member.member_no} ${user.member.name}`}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader><CardTitle>Ganti password</CardTitle><CardDescription>Minimal 10 karakter, berisi huruf dan angka.</CardDescription></CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="grid gap-4 sm:max-w-sm">
                            <FormField label="Password sekarang" error={form.errors.current_password}>
                                <Input type="password" autoComplete="current-password" value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)} />
                            </FormField>
                            <FormField label="Password baru" error={form.errors.password}>
                                <Input type="password" autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                            </FormField>
                            <FormField label="Ulangi password baru">
                                <Input type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} />
                            </FormField>
                            <div><Button type="submit" disabled={form.processing}>Simpan</Button></div>
                        </form>
                    </CardContent>
                </Card>
                <TwoFactorCard tf={twoFactor} />
            </div>
        </AppLayout>
    );
}
