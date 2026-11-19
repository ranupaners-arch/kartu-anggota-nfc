import { Children, cloneElement, isValidElement, type ReactElement, type ReactNode } from 'react';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

type CellProps = { 'data-label'?: string; children?: ReactNode };

/** Teks judul kolom untuk label di tampilan kartu (judul non-teks → tanpa label). */
const labelOf = (h: ReactNode) => (typeof h === 'string' || typeof h === 'number' ? String(h) : '');

/**
 * Beri setiap sel atribut data-label sesuai judul kolomnya. Di layar sempit (< 768 px) CSS
 * (app.css, [data-slot=data-table]) mengubah tiap baris menjadi kartu: "Label ........ isi".
 */
function withLabels(children: ReactNode, labels: string[]): ReactNode {
    return Children.map(children, (row) => {
        if (!isValidElement<{ children?: ReactNode }>(row)) return row;
        let i = 0;
        const cells = Children.map(row.props.children, (cell) => {
            if (!isValidElement<CellProps>(cell)) return cell;
            const label = labels[i++] ?? '';
            if (cell.props['data-label'] !== undefined) return cell;
            // Isi sel dibungkus satu elemen agar di tampilan kartu tidak terpecah jadi beberapa kolom flex.
            return cloneElement(cell as ReactElement<CellProps>, { 'data-label': label }, <span data-slot="cell-value">{cell.props.children}</span>);
        });
        return cloneElement(row, {}, cells);
    });
}

/** Tabel dalam kartu dengan baris kosong bawaan; di HP tampil sebagai daftar kartu. */
export function DataTable({ head, empty = 'Tidak ada data.', count, children }: { head: ReactNode[]; empty?: string; count: number; children: ReactNode }) {
    return (
        <Card className="py-0" data-slot="data-table">
            <Table>
                <TableHeader><TableRow>{head.map((h, i) => <TableHead key={i}>{h}</TableHead>)}</TableRow></TableHeader>
                <TableBody>
                    {count === 0
                        ? <TableRow><TableCell colSpan={head.length} data-empty="" className="h-20 text-center text-muted-foreground">{empty}</TableCell></TableRow>
                        : withLabels(children, head.map(labelOf))}
                </TableBody>
            </Table>
        </Card>
    );
}
