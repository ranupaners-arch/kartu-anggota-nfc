import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { AuthShell } from '@/components/auth-shell';
import { FormField } from '@/components/form-field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { SharedProps } from '@/types';

export default function ForgotPassword({ enabled }: { enabled: boolean }) {
    const status = usePage<SharedProps>().props.flash?.status;
    const form = useForm({ email: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/lupa-password', { preserveScroll: true });
    };

    return (
        <AuthShell title="Lupa password" description="Untuk pengurus, petugas, dan anggota pengguna app.">
            <Head title="Lupa password" />
            {!enabled ? (
                <div className="grid gap-4 text-sm">
                    <Alert><AlertDescription>Reset password lewat email belum aktif di organisasi ini. Hubungi pengurus (Super Admin) untuk mereset password Anda.</AlertDescription></Alert>
                    <Button variant="outline" render={<Link href="/masuk" />}>Kembali ke halaman masuk</Button>
                </div>
            ) : (
                <form onSubmit={submit} className="grid gap-4">
                    {status && <Alert role="status"><AlertDescription>{status}</AlertDescription></Alert>}
                    <p className="text-sm text-muted-foreground">Masukkan email akun Anda. Kami kirim tautan untuk membuat password baru.</p>
                    <FormField label="Email" error={form.errors.email}>
                        <Input type="email" autoComplete="username" autoFocus required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                    </FormField>
                    <Button type="submit" disabled={form.processing}>{form.processing ? 'Mengirim…' : 'Kirim tautan'}</Button>
                    <Button variant="link" render={<Link href="/masuk" />}>Kembali ke halaman masuk</Button>
                </form>
            )}
        </AuthShell>
    );
}
