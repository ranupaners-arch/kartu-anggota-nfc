import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import PortalLayout from '@/layouts/portal-layout';

type Props = { organization: string; term: string | null; name: string; email: string; divisions: { id: number; name: string }[] };

/** E-profil (web): data diri untuk didaftarkan sebagai anggota; disahkan oleh pengurus pusat. */
export default function PortalProfile({ organization, term, name, email, divisions }: Props) {
    const form = useForm<{ name: string; member_no: string; phone: string; cohort: string; division_id: string; photo: File | null; consent: boolean }>({
        name, member_no: '', phone: '', cohort: '', division_id: '', photo: null, consent: false,
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/saya/e-profil', { forceFormData: true });
    };
    return (
        <PortalLayout title="E-profil">
            <Head title="E-profil" />
            <p className="text-sm text-muted-foreground">Daftarkan keanggotaan di {organization}{term ? ` periode ${term}` : ''}. Setelah dikirim, pengurus pusat memeriksa dan mengesahkan, lalu kartu Anda aktif.</p>
            <form onSubmit={submit} className="grid gap-4">
                <FormField label="Nama lengkap" error={form.errors.name}>
                    <Input autoComplete="name" required maxLength={150} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                </FormField>
                <FormField label="NIM / nomor induk" hint="Dipakai sebagai nomor anggota." error={form.errors.member_no}>
                    <Input required maxLength={50} value={form.data.member_no} onChange={(e) => form.setData('member_no', e.target.value)} />
                </FormField>
                <FormField label="Email" hint="Dari akun Anda." error={(form.errors as Record<string, string>).email}>
                    <Input value={email} readOnly disabled />
                </FormField>
                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField label="No. HP (opsional)" error={form.errors.phone}>
                        <Input type="tel" autoComplete="tel" maxLength={30} value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                    </FormField>
                    <FormField label="Angkatan (opsional)" error={form.errors.cohort}>
                        <Input inputMode="numeric" maxLength={20} value={form.data.cohort} onChange={(e) => form.setData('cohort', e.target.value)} />
                    </FormField>
                </div>
                {divisions.length > 0 && (
                    <FormField label="Divisi yang diminati (opsional)" error={form.errors.division_id}>
                        <NativeSelect className="w-full" value={form.data.division_id} onChange={(e) => form.setData('division_id', e.target.value)}>
                            <NativeSelectOption value="">Belum memilih</NativeSelectOption>
                            {divisions.map((d) => <NativeSelectOption key={d.id} value={d.id}>{d.name}</NativeSelectOption>)}
                        </NativeSelect>
                    </FormField>
                )}
                <FormField label="Foto (opsional)" hint="Foto wajah jelas untuk kartu & pencocokan saat absensi. JPG/PNG, maks. 5 MB." error={form.errors.photo}>
                    <Input type="file" accept="image/jpeg,image/png,image/webp" capture="user" onChange={(e) => form.setData('photo', e.target.files?.[0] ?? null)} />
                </FormField>
                <div className="grid gap-1.5">
                    <Label className="flex items-start gap-2 font-normal leading-snug">
                        <Checkbox checked={form.data.consent} onCheckedChange={(v) => form.setData('consent', v === true)} className="mt-0.5" />
                        Saya setuju data diri saya (termasuk foto) diproses {organization} untuk keperluan keanggotaan dan absensi.
                    </Label>
                    {form.errors.consent && <p className="text-xs text-destructive">{form.errors.consent}</p>}
                </div>
                <Button type="submit" disabled={form.processing || !form.data.consent}>{form.processing ? 'Mengirim…' : 'Kirim e-profil'}</Button>
            </form>
        </PortalLayout>
    );
}
