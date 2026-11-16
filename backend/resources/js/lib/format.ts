const tz = 'Asia/Jakarta';

/** "16 Nov 2026" */
export const fmtDate = (v?: string | null) =>
    v ? new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: tz }).format(new Date(v)) : '—';

/** "16 Nov 2026 14.05" */
export const fmtDateTime = (v?: string | null) =>
    v ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: tz }).format(new Date(v)) : '—';

/** "14.05" */
export const fmtTime = (v?: string | null) =>
    v ? new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', timeZone: tz }).format(new Date(v)) : '—';

export const roleLabel: Record<string, string> = {
    super_admin: 'Super Admin',
    division_admin: 'Admin Divisi',
    scanner: 'Petugas Scan',
    member: 'Anggota',
};
