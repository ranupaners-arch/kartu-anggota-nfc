import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** Langkah kedua login: kode 6 digit dari aplikasi authenticator, atau kode cadangan. */
export default function TwoFactor() {
    const [recovery, setRecovery] = useState(false);
    const form = useForm({ code: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/masuk/2fa', { onError: () => form.reset('code') });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-muted/40 p-4">
            <Head title="Verifikasi 2FA" />
            <Card className="w-full max-w-sm">
                <CardHeader>
                    <CardTitle className="text-xl">Verifikasi dua langkah</CardTitle>
                    <CardDescription>
                        {recovery ? 'Masukkan salah satu kode cadangan (sekali pakai).' : 'Masukkan kode 6 digit dari aplikasi authenticator.'}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="code">{recovery ? 'Kode cadangan' : 'Kode'}</Label>
                            <Input id="code" autoFocus required autoComplete="one-time-code"
                                inputMode={recovery ? 'text' : 'numeric'} maxLength={recovery ? 20 : 6} placeholder={recovery ? 'XXXX-XXXX' : '123456'}
                                className={recovery ? '' : 'text-center text-lg tracking-[0.4em]'}
                                value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} aria-invalid={Boolean(form.errors.code)} />
                            {form.errors.code && <p className="text-sm text-destructive">{form.errors.code}</p>}
                        </div>
                        <Button type="submit" disabled={form.processing}>{form.processing ? 'Memeriksa…' : 'Verifikasi'}</Button>
                        <Button type="button" variant="link" onClick={() => { setRecovery(!recovery); form.reset('code'); form.clearErrors(); }}>
                            {recovery ? 'Pakai kode dari aplikasi' : 'HP hilang? Pakai kode cadangan'}
                        </Button>
                        <Link href="/masuk" className="text-center text-sm text-muted-foreground hover:underline">Kembali ke halaman masuk</Link>
                    </form>
                </CardContent>
            </Card>
        </div>
    );
}
