import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import AppLayout from '@/layouts/app-layout';
import { roleLabel } from '@/lib/format';

type U = { id?: number; name?: string; email?: string; role?: string; division_id?: number | null; member_no?: string | null; finance_role?: string | null };

export default function UserForm({ user, divisions }: { user: U; divisions: { id: number; name: string }[] }) {
    const isEdit = Boolean(user.id);
    const form = useForm({
        name: user.name ?? '', email: user.email ?? '', role: user.role ?? 'scanner',
        division_id: user.division_id ? String(user.division_id) : '', member_no: user.member_no ?? '', finance_role: user.finance_role ?? '',
    });
    const submit = (e: FormEvent) => { e.preventDefault(); if (isEdit) form.put(`/pengguna/${user.id}`); else form.post('/pengguna'); };
    const title = isEdit ? 'Ubah Pengguna' : 'Tambah Pengguna';

    return (
        <AppLayout title={title}>
            <Head title={title} />
            <Card className="max-w-3xl">
                <CardContent>
                    <form onSubmit={submit} className="grid gap-6">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField label="Nama" error={form.errors.name}><Input required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></FormField>
                            <FormField label="Email" error={form.errors.email}><Input type="email" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></FormField>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <FormField label="Peran" error={form.errors.role}>
                                <NativeSelect className="w-full" value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                                    {Object.entries(roleLabel).map(([v, l]) => <NativeSelectOption key={v} value={v}>{l}</NativeSelectOption>)}
                                </NativeSelect>
                            </FormField>
                            <FormField label="Divisi" hint="Wajib untuk Admin Divisi." error={form.errors.division_id}>
                                <NativeSelect className="w-full" value={form.data.division_id} onChange={(e) => form.setData('division_id', e.target.value)}>
                                    <NativeSelectOption value="">-</NativeSelectOption>
                                    {divisions.map((d) => <NativeSelectOption key={d.id} value={d.id}>{d.name}</NativeSelectOption>)}
                                </NativeSelect>
                            </FormField>
                            <FormField label="Tautkan ke nomor anggota" hint="Opsional." error={form.errors.member_no}>
                                <Input value={form.data.member_no} onChange={(e) => form.setData('member_no', e.target.value)} />
                            </FormField>
                        </div>
                        <FormField label="Akses keuangan" hint="Berlaku sampai periode berganti. Sekretaris hanya melihat." error={form.errors.finance_role}>
                            <NativeSelect className="w-full sm:w-64" value={form.data.finance_role} onChange={(e) => form.setData('finance_role', e.target.value)}>
                                <NativeSelectOption value="">Tidak ada</NativeSelectOption>
                                <NativeSelectOption value="treasurer">Bendahara</NativeSelectOption>
                                <NativeSelectOption value="auditor">Sekretaris (audit)</NativeSelectOption>
                            </NativeSelect>
                        </FormField>
                        {!isEdit && <p className="text-sm text-muted-foreground">Password sementara dibuat otomatis dan ditampilkan sekali setelah disimpan.</p>}
                        <div className="flex gap-2">
                            <Button type="submit" disabled={form.processing}>Simpan</Button>
                            <Button variant="ghost" render={<Link href="/pengguna" />}>Batal</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
