import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { roleLabel } from '@/lib/format';

interface Props { user: { name: string; email: string; role: string; member: { member_no: string; name: string } | null } }

export default function Account({ user }: Props) {
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
                <Card>
                    <CardHeader><CardTitle>Keamanan (2FA)</CardTitle><CardDescription>Verifikasi dua langkah untuk admin belum tersedia.</CardDescription></CardHeader>
                </Card>
            </div>
        </AppLayout>
    );
}
