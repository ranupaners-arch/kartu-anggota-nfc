import { useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { FormField } from '@/components/form-field';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { fmtDate } from '@/lib/format';

export type MemberDuty = {
    hasAccount: boolean;
    isSuperAdmin: boolean;
    current: { id: number; type: string; label: string; division: string | null; ends_on: string | null; created_at: string } | null;
    divisions: { id: number; name: string }[];
};

/** Akhiri tugas dengan alasan (mengundurkan diri, tidak bertugas lagi, …). */
export function EndDutyDialog({ dutyId, name }: { dutyId: number; name: string }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/tugas/${dutyId}/akhiri`, { preserveScroll: true, onSuccess: () => setOpen(false) });
    };
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={<Button size="sm" variant="outline" />}>Cabut tugas</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cabut tugas · {name}</DialogTitle>
                    <DialogDescription>Akun kembali sebagai anggota biasa dan dikeluarkan dari HP-nya. Data kehadiran & kartunya tetap.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <FormField label="Alasan" error={form.errors.reason}>
                        <Textarea rows={2} maxLength={300} required placeholder="Mis. mengundurkan diri, tidak bertugas lagi" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </FormField>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Batal</Button>
                        <Button type="submit" variant="destructive" disabled={form.processing}>Cabut tugas</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Kartu "Tugas" di detail anggota: tugas aktif + cabut, atau beri tugas Petugas Scan / Admin Divisi. */
export function DutyCard({ memberId, memberName, duty }: { memberId: number; memberName: string; duty: MemberDuty }) {
    const form = useForm<{ type: string; division_id: string; ends_on: string }>({ type: 'scanner', division_id: '', ends_on: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/anggota/${memberId}/tugas`, { preserveScroll: true });
    };
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2"><ShieldCheck className="size-4" /> Tugas</CardTitle>
                <CardDescription>Petugas Scan / Admin Divisi berlaku per periode; berakhir otomatis saat periode berganti atau pada tanggal yang dipilih.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
                {duty.isSuperAdmin ? (
                    <p className="text-muted-foreground">Akun ini Super Admin — tidak perlu tugas.</p>
                ) : !duty.hasAccount ? (
                    <Alert><AlertTitle>Belum punya akun app</AlertTitle><AlertDescription>Buat akun app untuk anggota ini dulu (Anggota → centang → Buat akun app), lalu beri tugas.</AlertDescription></Alert>
                ) : duty.current ? (
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <div className="font-medium">{duty.current.label}{duty.current.division ? ` · ${duty.current.division}` : ''}</div>
                            <div className="text-muted-foreground">Sejak {fmtDate(duty.current.created_at)} · {duty.current.ends_on ? `sampai ${fmtDate(duty.current.ends_on)}` : 'sampai akhir periode'}</div>
                        </div>
                        <EndDutyDialog dutyId={duty.current.id} name={memberName} />
                    </div>
                ) : (
                    <form onSubmit={submit} className="grid gap-3 sm:grid-cols-3 sm:items-start">
                        <FormField label="Jenis tugas" error={form.errors.type}>
                            <NativeSelect className="w-full" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                                <NativeSelectOption value="scanner">Petugas Scan</NativeSelectOption>
                                <NativeSelectOption value="division_admin">Admin Divisi</NativeSelectOption>
                            </NativeSelect>
                        </FormField>
                        {form.data.type === 'division_admin' && (
                            <FormField label="Divisi" error={form.errors.division_id}>
                                <NativeSelect className="w-full" value={form.data.division_id} onChange={(e) => form.setData('division_id', e.target.value)}>
                                    <NativeSelectOption value="">Pilih divisi</NativeSelectOption>
                                    {duty.divisions.map((d) => <NativeSelectOption key={d.id} value={String(d.id)}>{d.name}</NativeSelectOption>)}
                                </NativeSelect>
                            </FormField>
                        )}
                        <FormField label="Berakhir (opsional)" hint="Kosong = akhir periode." error={form.errors.ends_on}>
                            <Input type="date" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} />
                        </FormField>
                        <div className="sm:col-span-3"><Button type="submit" disabled={form.processing}>Beri tugas</Button></div>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}
