import { type ReactNode } from 'react';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

/** Tabel dalam kartu dengan baris kosong bawaan. */
export function DataTable({ head, empty = 'Tidak ada data.', count, children }: { head: ReactNode[]; empty?: string; count: number; children: ReactNode }) {
    return (
        <Card className="py-0">
            <Table>
                <TableHeader><TableRow>{head.map((h, i) => <TableHead key={i}>{h}</TableHead>)}</TableRow></TableHeader>
                <TableBody>
                    {count === 0 ? <TableRow><TableCell colSpan={head.length} className="h-20 text-center text-muted-foreground">{empty}</TableCell></TableRow> : children}
                </TableBody>
            </Table>
        </Card>
    );
}
