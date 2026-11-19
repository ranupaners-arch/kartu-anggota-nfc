import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/data-table';
import { EventCalendar, type Calendar } from '@/components/event-calendar';
import { FilterBar } from '@/components/filter-bar';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { PageTabs } from '@/components/page-tabs';
import { Pager } from '@/components/pager';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fmtDateTime, fmtTime } from '@/lib/format';
import type { Paginated } from '@/types';

type Row = {
    id: number; title: string; type: string; location: string | null; is_mandatory: boolean; closed_at: string | null;
    starts_at: string; checkin_opens_at: string; late_after: string; checkin_closes_at: string; present_count: number;
};

type Filters = { q: string | null; division: string | null; type: string | null; from: string | null; to: string | null };

export default function EventsIndex({ tab, events, calendar, allDivisions = [], filters, divisions, types }: {
    tab: string; events?: Paginated<Row>; calendar?: Calendar; allDivisions?: { id: number; name: string }[];
    filters: Filters; divisions: { id: number; name: string }[]; types: string[];
}) {
    const isCalendar = tab === 'kalender' && calendar;
    const [f, setF] = useState({ q: filters.q ?? '', division: filters.division ?? '', type: filters.type ?? '', from: filters.from ?? '', to: filters.to ?? '' });
    return (
        <AppLayout title="Kegiatan" actions={<Button render={<Link href="/kegiatan/create" />}><Plus /> Buat Kegiatan</Button>}>
            <Head title="Kegiatan" />
            <PageTabs tabs={{ berlangsung: 'Berlangsung', 'akan-datang': 'Akan Datang', selesai: 'Selesai', kalender: 'Kalender' }} current={tab} />
            <FilterBar url="/kegiatan" keep={isCalendar ? { tab, bulan: calendar.month } : { tab }} values={isCalendar ? { q: f.q, division: f.division, type: f.type } : f} search={f.q} onSearch={(q) => setF({ ...f, q })} placeholder="Cari judul / lokasi">
                {divisions.length > 0 && (
                    <NativeSelect value={f.division} onChange={(e) => setF({ ...f, division: e.target.value })} aria-label="Divisi">
                        <NativeSelectOption value="">Semua divisi</NativeSelectOption>
                        <NativeSelectOption value="umum">Kegiatan umum</NativeSelectOption>
                        {divisions.map((d) => <NativeSelectOption key={d.id} value={d.id}>{d.name}</NativeSelectOption>)}
                    </NativeSelect>
                )}
                {types.length > 1 && (
                    <NativeSelect value={f.type} onChange={(e) => setF({ ...f, type: e.target.value })} aria-label="Tipe">
                        <NativeSelectOption value="">Semua tipe</NativeSelectOption>
                        {types.map((t) => <NativeSelectOption key={t} value={t}>{t}</NativeSelectOption>)}
                    </NativeSelect>
                )}
                {!isCalendar && (
                    <>
                        <Input type="date" className="w-auto" aria-label="Dari tanggal" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
                        <span className="text-muted-foreground">–</span>
                        <Input type="date" className="w-auto" aria-label="Sampai tanggal" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} />
                    </>
                )}
            </FilterBar>
            {isCalendar ? (
                <EventCalendar calendar={calendar} divisions={allDivisions} query={Object.fromEntries(Object.entries({ q: filters.q ?? '', division: filters.division ?? '', type: filters.type ?? '' }).filter(([, v]) => v))} />
            ) : events && (
            <>
            <DataTable head={['Kegiatan', 'Mulai', 'Check-in', 'Lokasi', 'Hadir', 'Status']} count={events.data.length} empty="Tidak ada kegiatan.">
                {events.data.map((e) => (
                    <TableRow key={e.id}>
                        <TableCell>
                            <Link href={`/kegiatan/${e.id}`} className="font-medium hover:underline">{e.title}</Link>
                            <div className="text-xs text-muted-foreground">{e.type}{!e.is_mandatory && ' · tidak wajib'}</div>
                        </TableCell>
                        <TableCell>{fmtDateTime(e.starts_at)}</TableCell>
                        <TableCell>
                            {fmtTime(e.checkin_opens_at)}–{fmtTime(e.checkin_closes_at)}
                            <div className="text-xs text-muted-foreground">telat setelah {fmtTime(e.late_after)}</div>
                        </TableCell>
                        <TableCell>{e.location ?? '—'}</TableCell>
                        <TableCell className="tabular-nums">{e.present_count}</TableCell>
                        <TableCell><StatusBadge status={e.closed_at ? 'closed' : 'open'} /></TableCell>
                    </TableRow>
                ))}
            </DataTable>
            <Pager page={events} />
            </>
            )}
        </AppLayout>
    );
}
