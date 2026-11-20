import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import ScannerLayout from '@/layouts/scanner-layout';

export default function ScannerPassword() {
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/pindai/password', { onFinish: () => form.reset() });
    };
    return (
        <ScannerLayout title="Buat password baru">
            <Head title="Buat password baru" />
            <Alert><AlertDescription>Akun Anda memakai password sementara dari pengurus. Ganti dulu sebelum memindai.</AlertDescription></Alert>
            <form onSubmit={submit} className="grid gap-4">
                <FormField label="Password sementara" error={form.errors.current_password}>
                    <Input type="password" autoComplete="current-password" required value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)} />
                </FormField>
                <FormField label="Password baru" hint="Minimal 10 karakter, berisi huruf dan angka." error={form.errors.password}>
                    <Input type="password" autoComplete="new-password" required value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </FormField>
                <FormField label="Ulangi password baru">
                    <Input type="password" autoComplete="new-password" required value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} />
                </FormField>
                <Button type="submit" disabled={form.processing}>{form.processing ? 'Menyimpan…' : 'Simpan & lanjut'}</Button>
            </form>
        </ScannerLayout>
    );
}
