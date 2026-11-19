import { Head, Link, useForm } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { type FormEvent } from 'react';
import { AuthShell } from '@/components/auth-shell';
import { FormField } from '@/components/form-field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/** Atur ulang password dari tautan email. Untuk anggota (app), selesai di sini lalu masuk di app. */
export default function ResetPassword({ token, email, done }: { token: string; email: string; done?: boolean }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/atur-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    if (done) {
        return (
            <AuthShell title="Password diganti">
                <Head title="Password diganti" />
                <Alert role="status"><CircleCheck /><AlertDescription>Password baru sudah aktif. Buka app Kartu Anggota dan masuk dengan password baru. Semua perangkat lain sudah dikeluarkan.</AlertDescription></Alert>
            </AuthShell>
        );
    }

    return (
        <AuthShell title="Buat password baru" description={email || undefined}>
            <Head title="Buat password baru" />
            <form onSubmit={submit} className="grid gap-4">
                {form.errors.email && <Alert variant="destructive"><AlertDescription>{form.errors.email} <Link href="/lupa-password" className="underline">Minta tautan baru</Link></AlertDescription></Alert>}
                <FormField label="Password baru" hint="Minimal 10 karakter, berisi huruf dan angka." error={form.errors.password}>
                    <Input type="password" autoComplete="new-password" autoFocus required value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </FormField>
                <FormField label="Ulangi password baru">
                    <Input type="password" autoComplete="new-password" required value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} />
                </FormField>
                <Button type="submit" disabled={form.processing}>{form.processing ? 'Menyimpan…' : 'Simpan password'}</Button>
            </form>
        </AuthShell>
    );
}
