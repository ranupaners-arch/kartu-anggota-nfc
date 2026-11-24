import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import AppLayout from '@/layouts/app-layout';
import { toLocalInput } from '@/lib/format';
import type { SharedProps } from '@/types';
import { usePage } from '@inertiajs/react';

type Ev = {
    id?: number; title?: string; type?: string; location?: string | null; is_mandatory?: boolean;
    starts_at?: string; ends_at?: string; checkin_opens_at?: string; late_after?: string; checkin_closes_at?: string;
    rsvp_enabled?: boolean; rsvp_deadline?: string | null; quota?: number | null;
};

export default function EventForm({ event, divisions, windowLocked }: { event: Ev; divisions: { id: number; name: string }[]; windowLocked: boolean }) {
    const isEdit = Boolean(event.id);
    const superAdmin = usePage<SharedProps>().props.auth?.isSuperAdmin;
    const form = useForm({
        title: event.title ?? '', type: event.type ?? 'rapat', location: event.location ?? '',
        starts_at: toLocalInput(event.starts_at), ends_at: toLocalInput(event.ends_at),
        checkin_opens_at: toLocalInput(event.checkin_opens_at), late_after: toLocalInput(event.late_after),
        checkin_closes_at: toLocalInput(event.checkin_closes_at), is_mandatory: event.is_mandatory ?? true,
        rsvp_enabled: event.rsvp_enabled ?? false, rsvp_deadline: toLocalInput(event.rsvp_deadline), quota: event.quota?.toString() ?? '',
        division_id: '', target_division_ids: [] as number[], reason: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (isEdit) form.put(`/kegiatan/${event.id}`);
        else form.post('/kegiatan');
    };
    const dt = (key: 'starts_at' | 'ends_at' | 'checkin_opens_at' | 'late_after' | 'checkin_closes_at', label: string) => (
        <FormField label={label} error={form.errors[key]}>
            <Input type="datetime-local" required value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} />
        </FormField>
    );
    const toggleDivision = (id: number, on: boolean) =>
        form.setData('target_division_ids', on ? [...form.data.target_division_ids, id] : form.data.target_division_ids.filter((x) => x !== id));
    const title = isEdit ? 'Ubah Kegiatan' : 'Buat Kegiatan';

    return (
        <AppLayout title={title}>
            <Head title={title} />
            <Card className="max-w-4xl">
                <CardContent>
                    <form onSubmit={submit} className="grid gap-6">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <FormField label="Judul" error={form.errors.title}><Input required value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} /></FormField>
                            <FormField label="Tipe" error={form.errors.type}><Input value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} /></FormField>
                            <FormField label="Lokasi" error={form.errors.location}><Input value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} /></FormField>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">{dt('starts_at', 'Mulai')}{dt('ends_at', 'Selesai')}</div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            {dt('checkin_opens_at', 'Check-in dibuka')}{dt('late_after', 'Dianggap telat setelah')}{dt('checkin_closes_at', 'Check-in ditutup')}
                        </div>
                        {!isEdit && (
                            <div className="grid gap-4">
                                {superAdmin && (
                                    <FormField label="Milik divisi" hint="Kosongkan untuk kegiatan umum." error={form.errors.division_id}>
                                        <NativeSelect value={form.data.division_id} onChange={(e) => form.setData('division_id', e.target.value)}>
                                            <NativeSelectOption value="">— Kegiatan umum —</NativeSelectOption>
                                            {divisions.map((d) => <NativeSelectOption key={d.id} value={d.id}>{d.name}</NativeSelectOption>)}
                                        </NativeSelect>
                                    </FormField>
                                )}
                                <FormField label="Target peserta per divisi" hint="Kosongkan semua untuk menargetkan seluruh anggota aktif." error={form.errors.target_division_ids}>
                                    <div className="flex flex-wrap gap-4">
                                        {divisions.map((d) => (
                                            <Label key={d.id} className="flex items-center gap-2 font-normal">
                                                <Checkbox checked={form.data.target_division_ids.includes(d.id)} onCheckedChange={(v) => toggleDivision(d.id, v === true)} /> {d.name}
                                            </Label>
                                        ))}
                                    </div>
                                </FormField>
                            </div>
                        )}
                        <Label className="flex items-center gap-2 font-normal">
                            <Checkbox checked={form.data.is_mandatory} onCheckedChange={(v) => form.setData('is_mandatory', v === true)} />
                            Wajib hadir (yang tidak hadir ditandai Alpa saat kegiatan ditutup)
                        </Label>
                        <div className="grid gap-4">
                            <Label className="flex items-center gap-2 font-normal">
                                <Checkbox checked={form.data.rsvp_enabled} onCheckedChange={(v) => form.setData('rsvp_enabled', v === true)} />
                                Buka RSVP (anggota menjawab ikut / tidak ikut; tidak memengaruhi absensi)
                            </Label>
                            {form.data.rsvp_enabled && (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField label="Batas RSVP" hint="Kosongkan untuk menutup saat kegiatan mulai." error={form.errors.rsvp_deadline}>
                                        <Input type="datetime-local" value={form.data.rsvp_deadline} onChange={(e) => form.setData('rsvp_deadline', e.target.value)} />
                                    </FormField>
                                    <FormField label="Kuota" hint="Kosongkan bila tanpa batas. Hanya untuk kegiatan yang tidak wajib hadir." error={form.errors.quota}>
                                        <Input type="number" min={1} max={100000} value={form.data.quota} onChange={(e) => form.setData('quota', e.target.value)} />
                                    </FormField>
                                </div>
                            )}
                        </div>
                        {isEdit && windowLocked && (
                            <FormField label="Alasan perubahan" hint="Wajib bila jendela check-in diubah, karena kegiatan sudah berjalan." error={form.errors.reason}>
                                <Input value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                            </FormField>
                        )}
                        <div className="flex gap-2">
                            <Button type="submit" disabled={form.processing}>Simpan</Button>
                            <Button variant="ghost" render={<Link href={isEdit ? `/kegiatan/${event.id}` : '/kegiatan'} />}>Batal</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
