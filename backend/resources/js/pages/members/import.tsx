import { Head, Link, router, useForm } from '@inertiajs/react';
import { FileUp } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { DataTable } from '@/components/data-table';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

type Preview = {
    token: string; expires_in_minutes: number;
    summary: { valid: number; create: number; update: number; errors: number };
    errors: { line: number; member_no: string; errors: string[] }[];
    sample: { action: string; member_no: string; name: string; email: string | null; cohort: string | null; status: string }[];
};

export default function MemberImport({ preview }: { preview: Preview | null }) {
    const upload = useForm<{ file: File | null }>({ file: null });
    const [skip, setSkip] = useState(false);
    const [saving, setSaving] = useState(false);
    const submit = (e: FormEvent) => { e.preventDefault(); upload.post('/anggota/impor', { forceFormData: true }); };
    const commit = () => {
        setSaving(true);
        router.post('/anggota/impor/simpan', { token: preview!.token, skip_errors: skip ? '1' : '' }, { onFinish: () => setSaving(false) });
    };

    return (
        <AppLayout title="Impor Anggota">
            <Head title="Impor Anggota" />
            {!preview ? (
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Unggah file CSV</CardTitle>
                        <CardDescription>
                            Kolom wajib <code>member_no</code>, <code>name</code>. Opsional <code>email</code>, <code>phone</code>, <code>cohort</code>, <code>division</code> (nama divisi),{' '}
                            <code>status</code> (applicant/active/inactive/alumni). Pemisah koma atau titik koma (format Excel Indonesia). Nomor yang sudah ada akan diperbarui.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="flex flex-wrap items-center gap-2">
                            <Input type="file" accept=".csv,text/csv" className="w-auto" required onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)} />
                            <Button type="submit" disabled={upload.processing}><FileUp /> Pratinjau</Button>
                        </form>
                        {upload.errors.file && <p className="mt-2 text-sm text-destructive">{upload.errors.file}</p>}
                    </CardContent>
                </Card>
            ) : (
                <>
                    <Alert>
                        <AlertTitle>Pratinjau: {preview.summary.valid} baris valid ({preview.summary.create} baru, {preview.summary.update} diperbarui)</AlertTitle>
                        <AlertDescription>{preview.summary.errors} baris error. Pratinjau berlaku {preview.expires_in_minutes} menit.</AlertDescription>
                    </Alert>
                    {preview.errors.length > 0 && (
                        <>
                            <h2 className="font-semibold">Baris error</h2>
                            <DataTable head={['Baris', 'No. Anggota', 'Masalah']} count={preview.errors.length}>
                                {preview.errors.map((e) => <TableRow key={e.line}><TableCell>{e.line}</TableCell><TableCell>{e.member_no}</TableCell><TableCell className="whitespace-normal text-destructive">{e.errors.join(' ')}</TableCell></TableRow>)}
                            </DataTable>
                        </>
                    )}
                    <h2 className="font-semibold">Contoh data (10 pertama)</h2>
                    <DataTable head={['Aksi', 'No.', 'Nama', 'Email', 'Angkatan', 'Status']} count={preview.sample.length}>
                        {preview.sample.map((s) => <TableRow key={s.member_no}><TableCell>{s.action === 'create' ? 'Baru' : 'Perbarui'}</TableCell><TableCell>{s.member_no}</TableCell><TableCell>{s.name}</TableCell><TableCell>{s.email ?? '—'}</TableCell><TableCell>{s.cohort ?? '—'}</TableCell><TableCell>{s.status}</TableCell></TableRow>)}
                    </DataTable>
                    <div className="flex flex-wrap items-center gap-4">
                        {preview.errors.length > 0 && (
                            <Label className="flex items-center gap-2 font-normal"><Checkbox checked={skip} onCheckedChange={(v) => setSkip(v === true)} /> Lewati baris error dan simpan yang valid</Label>
                        )}
                        <Button disabled={saving || (preview.errors.length > 0 && !skip)} onClick={commit}>Simpan {preview.summary.valid} anggota</Button>
                        <Button variant="ghost" render={<Link href="/anggota/impor" />}>Unggah ulang</Button>
                    </div>
                </>
            )}
        </AppLayout>
    );
}
