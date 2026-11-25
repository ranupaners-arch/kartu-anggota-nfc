import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { FormField } from '@/components/form-field';
import { StatusBadge } from '@/components/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { fmtDateTime } from '@/lib/format';

export type SurveyData = {
    eligible: number; min: number; max_questions: number; max_days: number;
    survey: { id: number; open: boolean; closes_at: string; closed_at: string | null; questions: string[]; responses: number } | null;
    result: { items: { label: string; average: number; counts: number[] }[]; comments: string[] } | null;
};

/** Survei kepuasan anonim: buka, pantau jumlah jawaban, lihat hasil setelah selesai. */
export function SurveyTab({ eventId, closed, data }: { eventId: number; closed: boolean; data: SurveyData }) {
    const form = useForm({ questions: '', days: '7' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post(`/kegiatan/${eventId}/survei`, { preserveScroll: true }); };
    const s = data.survey;
    if (!s) {
        if (!closed) return <Alert><AlertDescription>Survei dibuka setelah kegiatan <b>ditutup</b>, supaya daftar hadirnya sudah final.</AlertDescription></Alert>;
        if (data.eligible < data.min) return <Alert><AlertDescription>Peserta hadir kurang dari {data.min} orang, jadi jawabannya tidak bisa anonim.</AlertDescription></Alert>;
        return (
            <Card className="max-w-3xl">
                <CardHeader>
                    <CardTitle>Buka survei kepuasan</CardTitle>
                    <CardDescription>Untuk {data.eligible} peserta yang hadir. Tanpa nama, sekali isi. Tidak bisa diubah setelah dibuka. Hasil butuh minimal {data.min} jawaban.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="grid gap-4">
                        <FormField label="Pertanyaan tambahan (opsional)" hint={`Satu baris satu pertanyaan, maks. ${data.max_questions}. Dinilai 1-5.`}
                            error={form.errors.questions ?? Object.entries(form.errors).find(([k]) => k.startsWith('questions.'))?.[1]}>
                            <Textarea className="min-h-24" placeholder={'Materi mudah dipahami\nWaktu pelaksanaan sesuai'} value={form.data.questions} onChange={(e) => form.setData('questions', e.target.value)} />
                        </FormField>
                        <div className="max-w-48"><FormField label="Dibuka selama (hari)" error={form.errors.days}><Input type="number" required min={1} max={data.max_days} value={form.data.days} onChange={(e) => form.setData('days', e.target.value)} /></FormField></div>
                        <div><Button type="submit" disabled={form.processing}>{form.processing ? 'Membuka…' : 'Buka survei'}</Button></div>
                    </form>
                </CardContent>
            </Card>
        );
    }
    return (
        <>
            <Card className="max-w-3xl">
                <CardHeader>
                    <CardTitle className="flex flex-wrap items-center gap-2">Survei kepuasan <StatusBadge status={s.open ? 'open' : 'closed'} label={s.open ? 'Terbuka' : 'Selesai'} /></CardTitle>
                    <CardDescription>{s.responses} dari {data.eligible} peserta hadir sudah mengisi. {s.open ? `Terbuka sampai ${fmtDateTime(s.closes_at)}.` : `Selesai ${fmtDateTime(s.closed_at)}.`}</CardDescription>
                </CardHeader>
                <CardContent className="grid gap-3 text-sm">
                    {s.open && <p className="text-muted-foreground">Hasil tampil setelah survei selesai.</p>}
                    {s.open && s.questions.length > 0 && <ol className="list-decimal pl-5">{s.questions.map((q) => <li key={q}>{q}</li>)}</ol>}
                    {s.open && <div><ConfirmAction title="Tutup survei sekarang?" description="Peserta yang belum mengisi tidak bisa mengisi lagi, dan survei tidak bisa dibuka kembali." href={`/kegiatan-survei/${s.id}/tutup`} confirmLabel="Tutup survei">Tutup lebih awal</ConfirmAction></div>}
                    {!s.open && !data.result && <p className="text-muted-foreground">Hasil tidak ditampilkan karena jawaban kurang dari {data.min}.</p>}
                </CardContent>
            </Card>
            {data.result && (
                <div className="grid gap-3 md:grid-cols-2">
                    {data.result.items.map((i) => (
                        <Card key={i.label} className="gap-3">
                            <CardHeader><CardDescription className="break-words">{i.label}</CardDescription><CardTitle className="text-2xl tabular-nums">{i.average.toLocaleString('id-ID')} <span className="text-sm font-normal text-muted-foreground">dari 5</span></CardTitle></CardHeader>
                            <CardContent className="grid gap-1">
                                {[5, 4, 3, 2, 1].map((n) => (
                                    <div key={n} className="flex items-center gap-2 text-xs">
                                        <span className="w-3 tabular-nums">{n}</span>
                                        <div className="h-2 flex-1 rounded-full bg-muted"><div className="h-2 rounded-full bg-foreground/70" style={{ width: `${(i.counts[n - 1] / s.responses) * 100}%` }} /></div>
                                        <span className="w-8 text-right tabular-nums text-muted-foreground">{i.counts[n - 1]}</span>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
            {data.result && data.result.comments.length > 0 && (
                <Card className="max-w-3xl">
                    <CardHeader><CardTitle>Saran ({data.result.comments.length})</CardTitle></CardHeader>
                    <CardContent><ul className="divide-y">{data.result.comments.map((c, i) => <li key={i} className="py-2 text-sm break-words whitespace-pre-line">{c}</li>)}</ul></CardContent>
                </Card>
            )}
        </>
    );
}
