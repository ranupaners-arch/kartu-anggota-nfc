import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, FileDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import PortalLayout from '@/layouts/portal-layout';
import { fmtDate, fmtSize } from '@/lib/format';

type Item = { event_id: number; title: string; date: string; content: string | null; updated_at: string | null; documents: { id: number; title: string; size: number; file_name: string }[] };

export default function PortalMinutes({ items }: { items: Item[] }) {
    return (
        <PortalLayout title="Notulen & dokumen">
            <Head title="Notulen & dokumen" />
            <div><Button variant="ghost" size="sm" render={<Link href="/saya/kegiatan" />}><ArrowLeft /> Kegiatan</Button></div>
            {items.length === 0 && <Card><CardHeader><CardTitle className="text-base">Belum ada notulen</CardTitle><CardDescription>Notulen dan dokumen kegiatan yang Anda ikuti akan muncul di sini.</CardDescription></CardHeader></Card>}
            {items.map((i) => (
                <Card key={i.event_id} className="gap-3">
                    <CardHeader><CardTitle className="text-base">{i.title}</CardTitle><CardDescription>{fmtDate(i.date)}</CardDescription></CardHeader>
                    <CardContent className="grid gap-3">
                        {i.content && (
                            <details className="text-sm">
                                <summary className="cursor-pointer font-medium">Baca notulen</summary>
                                <p className="mt-2 break-words whitespace-pre-line">{i.content}</p>
                            </details>
                        )}
                        {i.documents.length > 0 && (
                            <ul className="divide-y">
                                {i.documents.map((d) => (
                                    <li key={d.id} className="flex items-center gap-3 py-2 text-sm">
                                        <span className="min-w-0 flex-1 break-words">{d.title}<span className="block text-xs text-muted-foreground">{d.file_name.split('.').pop()?.toUpperCase()} · {fmtSize(d.size)}</span></span>
                                        <Button size="sm" variant="outline" render={<a href={`/saya/dokumen/${d.id}`} aria-label={`Unduh ${d.title}`} />}><FileDown /> Unduh</Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            ))}
        </PortalLayout>
    );
}
