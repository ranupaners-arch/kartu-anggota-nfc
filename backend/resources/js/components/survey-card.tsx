import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { fmtDateTime } from '@/lib/format';

export type Survey = { id: number; event: string; date: string; closes_at: string; questions: string[] };

function Rating({ label, value, onChange }: { label: string; value: number; onChange: (n: number) => void }) {
    return (
        <FormField label={label}>
            <div className="grid grid-cols-5 gap-1.5">
                {[1, 2, 3, 4, 5].map((n) => <Button key={n} type="button" variant={value === n ? 'default' : 'outline'} aria-pressed={value === n} onClick={() => onChange(n)}>{n}</Button>)}
            </div>
        </FormField>
    );
}

/** Survei kegiatan yang belum diisi anggota (tanpa nama, sekali isi). */
export function SurveyCard({ survey: s }: { survey: Survey }) {
    const form = useForm({ rating: 0, answers: s.questions.map(() => 0), comment: '' });
    const ready = form.data.rating > 0 && form.data.answers.every((a) => a > 0);
    const submit = (e: FormEvent) => { e.preventDefault(); form.post(`/saya/survei/${s.id}`, { preserveScroll: true }); };
    return (
        <Card className="gap-3">
            <CardHeader><CardTitle className="text-base">Survei: {s.event}</CardTitle><CardDescription>Tanpa nama, sampai {fmtDateTime(s.closes_at)}. 1 = sangat kurang, 5 = sangat baik.</CardDescription></CardHeader>
            <CardContent>
                <details>
                    <summary className="cursor-pointer text-sm font-medium">Isi survei</summary>
                    <form onSubmit={submit} className="mt-3 grid gap-4">
                        <Rating label="Kepuasan keseluruhan" value={form.data.rating} onChange={(n) => form.setData('rating', n)} />
                        {s.questions.map((q, i) => <Rating key={q} label={q} value={form.data.answers[i]} onChange={(n) => form.setData((d) => ({ ...d, answers: d.answers.map((a, j) => (j === i ? n : a)) }))} />)}
                        <FormField label="Saran (opsional)" hint="Jangan menulis nama bila ingin tetap anonim." error={form.errors.comment}>
                            <Textarea maxLength={1000} value={form.data.comment} onChange={(e) => form.setData('comment', e.target.value)} />
                        </FormField>
                        <Button type="submit" disabled={!ready || form.processing}>{form.processing ? 'Mengirim…' : 'Kirim jawaban'}</Button>
                    </form>
                </details>
            </CardContent>
        </Card>
    );
}
