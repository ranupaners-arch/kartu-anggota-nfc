import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SharedProps } from '@/types';

export default function Login({ canResetPassword = false }: { canResetPassword?: boolean }) {
    const status = usePage<SharedProps>().props.flash?.status;
    const form = useForm({ email: '', password: '', remember: false });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/masuk', { onFinish: () => form.reset('password') });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-muted/40 p-4">
            <Head title="Masuk" />
            <div className="flex w-full max-w-sm flex-col items-center gap-6">
                <div className="flex flex-col items-center gap-3">
                    <img src="/brand/logo-2d.svg" alt="Logo Kartu Anggota" className="size-20 drop-shadow-md" />
                    <span className="text-lg font-semibold tracking-tight">Kartu Anggota</span>
                </div>
                <Card className="w-full">
                <CardHeader>
                    <CardTitle className="text-xl">Masuk</CardTitle>
                    <CardDescription>Pengurus ke dashboard; anggota ke portal anggota (QR Saya, kegiatan, izin, riwayat).</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="grid gap-4">
                        {status && <Alert role="status"><AlertDescription>{status}</AlertDescription></Alert>}
                        {form.errors.email && (
                            <Alert variant="destructive"><AlertDescription>{form.errors.email}</AlertDescription></Alert>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" type="email" autoComplete="username" autoFocus required
                                value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        </div>
                        <div className="grid gap-2">
                            <div className="flex items-center justify-between">
                                <Label htmlFor="password">Password</Label>
                                {canResetPassword && <Link href="/lupa-password" className="text-sm text-muted-foreground underline-offset-4 hover:underline">Lupa password?</Link>}
                            </div>
                            <Input id="password" type="password" autoComplete="current-password" required
                                value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                        </div>
                        <Label className="flex items-center gap-2 font-normal">
                            <Checkbox checked={form.data.remember} onCheckedChange={(v) => form.setData('remember', v === true)} />
                            Ingat saya
                        </Label>
                        <Button type="submit" disabled={form.processing}>{form.processing ? 'Memproses…' : 'Masuk'}</Button>
                    </form>
                </CardContent>
                </Card>
            </div>
        </div>
    );
}
