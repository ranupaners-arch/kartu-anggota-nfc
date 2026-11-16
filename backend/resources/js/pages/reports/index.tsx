import { Head, router, usePage } from '@inertiajs/react';
import { Download, FileSpreadsheet, FileText } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/data-table';
import { PageTabs } from '@/components/page-tabs';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDateTime } from '@/lib/format';
import type { Paginated, SharedProps } from '@/types';

type Row = { member_id: number; member_no: string; name: string; division: string | null; hadir: number; telat: number; izin: number; sakit: number; alpa: number; attendance_rate: number | null };
type Exp = { id: number; report: string; format: string; status: string; error: string | null; created_at: string };

interface Props {
    tab: string; terms: { id: number; name: string }[]; term: { id: number; name: string } | null; divisionId: number | null;
    divisions: { id: number; name: string }[]; report: { events: number; average_rate: number | null; rows: Row[] } | null; exports: Paginated<Exp> | null;
}

export default function Reports({ tab, terms, term, divisionId, divisions, report, exports }: Props) {
    const superAdmin = usePage<SharedProps>().props.auth?.isSuperAdmin;
    const [termId, setTermId] = useState(String(term?.id ?? ''));
    const [division, setDivision] = useState(String(divisionId ?? ''));
    const show = () => router.get('/rekap', { tab, term_id: termId, division_id: division || undefined }, { preserveState: true });
    const exportAs = (format: 'xlsx' | 'pdf') => router.post('/ekspor', { report: 'term', term_id: term?.id, division_id: divisionId ?? '', format });
    const tabs: Record<string, string> = superAdmin ? { periode: 'Periode', divisi: 'Divisi', ekspor: 'Hasil Ekspor' } : { divisi: 'Divisi', ekspor: 'Hasil Ekspor' };

    return (
        <AppLayout title="Rekap & Ekspor">
            <Head title="Rekap & Ekspor" />
            <PageTabs tabs={tabs} current={tab} />
            {tab === 'ekspor' && exports ? (
                <>
                    <p className="text-sm text-muted-foreground">Ekspor diproses di latar belakang. Muat ulang halaman untuk memperbarui status.</p>
                    <DataTable head={['Dibuat', 'Laporan', 'Format', 'Status', '']} count={exports.data.length} empty="Belum ada ekspor.">
                        {exports.data.map((x) => (
                            <TableRow key={x.id}>
                                <TableCell>{fmtDateTime(x.created_at)}</TableCell><TableCell>{x.report === 'event' ? 'Kegiatan' : 'Periode'}</TableCell>
                                <TableCell className="uppercase">{x.format}</TableCell>
                                <TableCell><StatusBadge status={x.status} />{x.error && <div className="mt-1 text-xs text-destructive">{x.error}</div>}</TableCell>
                                <TableCell className="text-right">{x.status === 'ready' && <Button size="sm" variant="outline" render={<a href={`/ekspor/${x.id}/unduh`} />}><Download /> Unduh</Button>}</TableCell>
                            </TableRow>
                        ))}
                    </DataTable>
                    <Pager page={exports} />
                </>
            ) : (
                <>
                    <div className="flex flex-wrap items-center gap-2">
                        <NativeSelect value={termId} onChange={(e) => setTermId(e.target.value)}>
                            {terms.map((t) => <NativeSelectOption key={t.id} value={t.id}>{t.name}</NativeSelectOption>)}
                        </NativeSelect>
                        {tab === 'divisi' && (
                            <NativeSelect value={division} onChange={(e) => setDivision(e.target.value)}>
                                <NativeSelectOption value="">Pilih divisi</NativeSelectOption>
                                {divisions.map((d) => <NativeSelectOption key={d.id} value={d.id}>{d.name}</NativeSelectOption>)}
                            </NativeSelect>
                        )}
                        <Button variant="secondary" onClick={show}>Tampilkan</Button>
                        <div className="flex-1" />
                        {report && (
                            <>
                                <Button variant="outline" onClick={() => exportAs('xlsx')}><FileSpreadsheet /> Ekspor Excel</Button>
                                <Button variant="outline" onClick={() => exportAs('pdf')}><FileText /> Ekspor PDF</Button>
                            </>
                        )}
                    </div>
                    {report ? (
                        <>
                            <p className="text-sm text-muted-foreground">{report.events} kegiatan · rata-rata kehadiran {report.average_rate ?? '–'}%</p>
                            <DataTable head={['No.', 'Nama', 'Divisi', 'Hadir', 'Telat', 'Izin', 'Sakit', 'Alpa', '% Hadir']} count={report.rows.length}>
                                {report.rows.map((r) => (
                                    <TableRow key={r.member_id}>
                                        <TableCell className="tabular-nums">{r.member_no}</TableCell><TableCell>{r.name}</TableCell><TableCell>{r.division ?? '—'}</TableCell>
                                        {[r.hadir, r.telat, r.izin, r.sakit, r.alpa].map((n, i) => <TableCell key={i} className="tabular-nums">{n}</TableCell>)}
                                        <TableCell className="font-medium tabular-nums">{r.attendance_rate ?? '–'}</TableCell>
                                    </TableRow>
                                ))}
                            </DataTable>
                        </>
                    ) : tab === 'divisi' && <p className="text-sm text-muted-foreground">Pilih divisi untuk melihat rekap.</p>}
                </>
            )}
        </AppLayout>
    );
}
