import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/masuk', { onFinish: () => form.reset('password') });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-muted/40 p-4">
            <Head title="Masuk" />
            <Card className="w-full max-w-sm">
                <CardHeader>
                    <CardTitle className="text-xl">Masuk Dashboard</CardTitle>
                    <CardDescription>Khusus pengurus organisasi.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="grid gap-4">
                        {form.errors.email && (
                            <Alert variant="destructive"><AlertDescription>{form.errors.email}</AlertDescription></Alert>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" type="email" autoComplete="username" autoFocus required
                                value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
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
    );
}
