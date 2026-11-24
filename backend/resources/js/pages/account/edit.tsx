import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CalendarPlus, Check, Copy, ExternalLink, Mail } from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { type FormEvent, useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { roleLabel } from '@/lib/format';
import type { SharedProps } from '@/types';
import { PushCard } from '@/components/push-card';
import { type TwoFactor, TwoFactorCard } from '@/components/two-factor-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

interface Props { user: { name: string; email: string; role: string; member: { member_no: string; name: string } | null }; twoFactor: TwoFactor; calendarUrl: string | null; weeklySummary: { enabled: boolean; mailEnabled: boolean }; pushKey: string | null }

/** Ringkasan mingguan lewat email (Senin pagi): nyala/mati + contoh dengan data saat ini. */
function WeeklySummaryCard({ ws }: { ws: { enabled: boolean; mailEnabled: boolean } }) {
    const toggle = (v: boolean) => router.put('/akun/ringkasan', { enabled: v }, { preserveScroll: true });
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2"><Mail className="size-4" /> Ringkasan mingguan</CardTitle>
                <CardDescription>Tiap Senin 07.00: kehadiran 7 hari terakhir, izin & tinjauan yang menunggu, anggota yang perlu perhatian, dan kegiatan pekan ini.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
                <Label className="flex items-center gap-2 font-normal">
                    <Checkbox checked={ws.enabled} onCheckedChange={(v) => toggle(v === true)} /> Kirim ke email saya
                </Label>
                {!ws.mailEnabled && <p className="text-muted-foreground">Email server belum diatur, jadi ringkasan belum terkirim. Super Admin bisa mengaturnya (Pengaturan → Sistem).</p>}
                <div><Button variant="outline" size="sm" render={<a href="/akun/ringkasan/contoh" target="_blank" rel="noreferrer" />}><ExternalLink /> Lihat contoh</Button></div>
            </CardContent>
        </Card>
    );
}

/** Tautan langganan jadwal (.ics) untuk Google Calendar / kalender HP. */
function CalendarCard({ url }: { url: string | null }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        if (!url) return;
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch { /* clipboard ditolak: pengguna bisa salin manual dari kotak */ }
    };
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2"><CalendarPlus className="size-4" /> Langganan jadwal</CardTitle>
                <CardDescription>Tampilkan kegiatan yang bisa Anda kelola di Google Calendar atau kalender HP, diperbarui otomatis.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
                {url ? (
                    <>
                        <div className="flex gap-2">
                            <Input readOnly value={url} aria-label="Tautan langganan jadwal" onFocus={(e) => e.currentTarget.select()} className="font-mono text-xs" />
                            <Button variant="outline" onClick={copy} aria-live="polite">{copied ? <><Check /> Tersalin</> : <><Copy /> Salin</>}</Button>
                        </div>
                        <p className="text-muted-foreground">Google Calendar (komputer): <b>Setelan → Tambahkan kalender → Dari URL</b>, tempel tautan ini. Kalender Apple: <b>Tambah langganan kalender</b>. Pembaruan bisa tertunda beberapa jam (diatur aplikasi kalendernya).</p>
                        <p className="text-muted-foreground">Rahasiakan tautan ini — siapa pun yang memilikinya bisa melihat jadwal Anda.</p>
                        <div>
                            <ConfirmAction size="sm" title="Ganti tautan langganan?" description="Tautan lama langsung tidak berlaku; kalender yang memakai tautan lama perlu ditambahkan ulang." href="/akun/kalender" confirmLabel="Ganti tautan">Ganti tautan</ConfirmAction>
                        </div>
                    </>
                ) : (
                    <div><Button onClick={() => router.post('/akun/kalender', {}, { preserveScroll: true })}><CalendarPlus /> Buat tautan langganan</Button></div>
                )}
            </CardContent>
        </Card>
    );
}

export default function Account({ user, twoFactor, calendarUrl, weeklySummary, pushKey }: Props) {
    const mustChange = usePage<SharedProps>().props.auth?.mustChangePassword ?? false;
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/akun/password', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <AppLayout title="Akun Saya">
            <Head title="Akun Saya" />
            <div className="grid max-w-3xl gap-4">
                {mustChange && (
                    <Alert>
                        <AlertTitle>Ganti password sementara Anda</AlertTitle>
                        <AlertDescription>Akun ini memakai password yang dibuat pengurus. Ganti dulu di bawah sebelum memakai menu lain.</AlertDescription>
                    </Alert>
                )}
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
                <TwoFactorCard tf={twoFactor} base="/akun/2fa" />
                <CalendarCard url={calendarUrl} />
                <WeeklySummaryCard ws={weeklySummary} />
                {user.role === 'super_admin' && <PushCard publicKey={pushKey} endpoint="/akun/notifikasi" description="Pendaftar baru dan laporan kartu (hilang/rusak/HP tanpa NFC) langsung ke perangkat ini." />}
            </div>
        </AppLayout>
    );
}
