import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import AppLayout from '@/layouts/app-layout';

type M = { id?: number; member_no?: string; name?: string; email?: string | null; phone?: string | null; cohort?: string | null };
type Ms = { division_id: number | null; status: string; role: string | null };
const statuses: Record<string, string> = { applicant: 'Pendaftar', active: 'Aktif', inactive: 'Tidak aktif', alumni: 'Alumni' };

export default function MemberForm({ member, membership, divisions }: { member: M; membership: Ms; divisions: { id: number; name: string }[] }) {
    const isEdit = Boolean(member.id);
    const form = useForm({
        member_no: member.member_no ?? '', name: member.name ?? '', email: member.email ?? '', phone: member.phone ?? '', cohort: member.cohort ?? '',
        division_id: membership.division_id ? String(membership.division_id) : '', status: membership.status ?? 'active', role: membership.role ?? '',
        consent: false,
    });
    const submit = (e: FormEvent) => { e.preventDefault(); if (isEdit) form.put(`/anggota/${member.id}`); else form.post('/anggota'); };
    const text = (key: 'member_no' | 'name' | 'email' | 'phone' | 'cohort' | 'role', label: string, props: React.ComponentProps<typeof Input> = {}) => (
        <FormField label={label} error={form.errors[key]}><Input value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} {...props} /></FormField>
    );
    const title = isEdit ? 'Ubah Anggota' : 'Tambah Anggota';

    return (
        <AppLayout title={title}>
            <Head title={title} />
            <Card className="max-w-3xl">
                <CardContent>
                    <form onSubmit={submit} className="grid gap-6">
                        <div className="grid gap-4 sm:grid-cols-3">
                            {text('member_no', 'Nomor anggota', { required: true })}{text('name', 'Nama', { required: true })}{text('cohort', 'Angkatan')}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">{text('email', 'Email', { type: 'email' })}{text('phone', 'Telepon')}</div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <FormField label="Divisi (periode ini)" error={form.errors.division_id}>
                                <NativeSelect className="w-full" value={form.data.division_id} onChange={(e) => form.setData('division_id', e.target.value)}>
                                    <NativeSelectOption value="">—</NativeSelectOption>
                                    {divisions.map((d) => <NativeSelectOption key={d.id} value={d.id}>{d.name}</NativeSelectOption>)}
                                </NativeSelect>
                            </FormField>
                            <FormField label="Status keanggotaan" error={form.errors.status}>
                                <NativeSelect className="w-full" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                    {Object.entries(statuses).map(([v, l]) => <NativeSelectOption key={v} value={v}>{l}</NativeSelectOption>)}
                                </NativeSelect>
                            </FormField>
                            {text('role', 'Jabatan')}
                        </div>
                        {!isEdit && (
                            <div className="grid gap-1">
                                <Label className="flex items-start gap-2 font-normal">
                                    <Checkbox className="mt-0.5" checked={form.data.consent} onCheckedChange={(v) => form.setData('consent', v === true)} />
                                    Anggota sudah menyetujui data dirinya (termasuk foto) diproses organisasi untuk keperluan absensi.
                                </Label>
                                {form.errors.consent && <p className="text-sm text-destructive">{form.errors.consent}</p>}
                            </div>
                        )}
                        <div className="flex gap-2">
                            <Button type="submit" disabled={form.processing || (!isEdit && !form.data.consent)}>Simpan</Button>
                            <Button variant="ghost" render={<Link href={isEdit ? `/anggota/${member.id}` : '/anggota'} />}>Batal</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
