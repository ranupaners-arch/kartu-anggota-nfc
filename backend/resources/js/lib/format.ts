const tz = 'Asia/Jakarta';

/** "16 Nov 2026" */
export const fmtDate = (v?: string | null) =>
    v ? new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: tz }).format(new Date(v)) : '-';

/** "16 Nov 2026 14.05" */
export const fmtDateTime = (v?: string | null) =>
    v ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: tz }).format(new Date(v)) : '-';

/** "14.05" */
export const fmtTime = (v?: string | null) =>
    v ? new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', timeZone: tz }).format(new Date(v)) : '-';

export const roleLabel: Record<string, string> = {
    super_admin: 'Super Admin',
    division_admin: 'Admin Divisi',
    scanner: 'Petugas Scan',
    member: 'Anggota',
};

/** ISO → nilai input datetime-local (zona Asia/Jakarta), mis. "2026-11-16T14:05". */
export function toLocalInput(v?: string | null): string {
    if (!v) return '';
    const p = Object.fromEntries(
        new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
            .formatToParts(new Date(v)).map((x) => [x.type, x.value]),
    );
    return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
}

/** "120 KB" / "1,4 MB" */
export const fmtSize = (n: number) => (n < 1024 * 1024 ? `${Math.max(1, Math.round(n / 1024))} KB` : `${(n / 1048576).toLocaleString('id-ID', { maximumFractionDigits: 1 })} MB`);
