import { IdCard, Nfc, UserRound } from 'lucide-react';
import { cn } from '@/lib/utils';

export type MemberCardInfo = {
    state: 'none' | 'pending' | 'active' | 'inactive';
    status?: string | null;
    organization: string | null;
    term: string | null;
    valid_until: string | null;
    member: { name: string; member_no: string; division: string | null; cohort?: string | null; photo_url: string | null } | null;
    card?: { last4: string } | null;
};

const initials = (name?: string) => (name ?? '').trim().split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');

/**
 * Kartu anggota (rasio CR80, desain sama dengan app & PDF cetak). Ukuran teks mengikuti lebar kartu
 * (container query), jadi tampil proporsional dari HP sampai desktop.
 */
export function MemberCard({ info, onRegister, className }: { info: MemberCardInfo; onRegister?: () => void; className?: string }) {
    if (info.state === 'none') {
        const Tag = onRegister ? 'button' : 'div';
        return (
            <Tag type={onRegister ? 'button' : undefined} onClick={onRegister}
                className={cn('@container flex aspect-[85.6/54] w-full flex-col rounded-[5cqw] border-2 border-dashed bg-card p-[6cqw] text-left', onRegister && 'hover:bg-muted/50', className)}>
                <span className="flex items-center gap-[2.5cqw] text-[3.6cqw] text-muted-foreground">
                    <img src="/brand/logo-2d.svg" alt="" className="size-[8cqw] opacity-60" />
                    <span className="truncate">{info.organization ?? 'Kartu Anggota'}</span>
                </span>
                <span className="flex flex-1 flex-col justify-center">
                    <IdCard className="size-[8cqw] text-indigo-600 dark:text-indigo-400" aria-hidden="true" />
                    <span className="mt-[1.5cqw] text-[5cqw] leading-tight font-semibold">Daftarkan keanggotaan<br />dengan e-profil</span>
                    <span className="mt-[1cqw] text-[3.3cqw] text-muted-foreground">Isi data diri, lalu pengurus pusat mengesahkan dan menerbitkan kartu Anda.</span>
                </span>
            </Tag>
        );
    }
    const active = info.state === 'active';
    const m = info.member!;
    const valid = info.valid_until ? `${info.valid_until.slice(5, 7)}/${info.valid_until.slice(2, 4)}` : null;
    const badge = info.state === 'pending' ? 'MENUNGGU PENGESAHAN' : info.state === 'inactive' ? (info.status === 'alumni' ? 'ALUMNI' : 'TIDAK AKTIF') : null;
    return (
        <div role="img" aria-label={[`Kartu anggota ${info.organization ?? ''}`, m.name, `nomor ${m.member_no}`, badge?.toLowerCase()].filter(Boolean).join(', ')}
            className={cn('@container relative aspect-[85.6/54] w-full overflow-hidden rounded-[5cqw] text-white shadow-lg',
                active ? 'bg-gradient-to-br from-[#4F46E5] to-[#312E81] shadow-indigo-500/30' : 'bg-gradient-to-br from-[#6B7280] to-[#374151]', className)}>
            <span className="absolute -top-[20cqw] -right-[18cqw] size-[60cqw] rounded-full bg-white/[0.08]" aria-hidden="true" />
            <span className="absolute -bottom-[32cqw] right-[12cqw] size-[55cqw] rounded-full bg-white/[0.06]" aria-hidden="true" />
            <div className="relative flex h-full flex-col p-[5.5cqw]" aria-hidden="true">
                <div className="flex items-center gap-[2.5cqw]">
                    <img src="/brand/logo-2d.svg" alt="" className="size-[8.5cqw]" />
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-[4cqw] font-bold">{info.organization ?? 'Kartu Anggota'}</div>
                        <div className="text-[3cqw] tracking-wide text-white/75">KARTU ANGGOTA</div>
                    </div>
                    <Nfc className="size-[6.5cqw] text-white/85" />
                </div>
                <div className="mt-auto flex items-end gap-[3cqw]">
                    <div className="min-w-0 flex-1">
                        <div className="mb-[3cqw] h-[7.5cqw] w-[10cqw] rounded-[1.2cqw] bg-gradient-to-r from-[#FDE68A] to-[#D4A017]" />
                        {badge && <div className="mb-[2cqw] inline-block rounded-[1cqw] bg-white/20 px-[2cqw] py-[0.6cqw] text-[3cqw] font-bold tracking-wide">{badge}</div>}
                        <div className="text-[5cqw] tracking-[0.6cqw] tabular-nums">{m.member_no}</div>
                        <div className="mt-[0.8cqw] truncate text-[4.2cqw] font-semibold uppercase">{m.name}</div>
                        <div className="mt-[0.6cqw] truncate text-[3cqw] tracking-wide text-white/75">{[m.division, valid && `BERLAKU S.D. ${valid}`].filter(Boolean).join('  ·  ')}</div>
                    </div>
                    <div className="h-[25cqw] w-[20cqw] shrink-0 overflow-hidden rounded-[2.5cqw] border-2 border-white/85 bg-white/15">
                        {m.photo_url
                            ? <img src={m.photo_url} alt="" className="size-full object-cover" />
                            : <span className="flex size-full items-center justify-center text-[7cqw] font-bold">{initials(m.name) || <UserRound className="size-1/2" />}</span>}
                    </div>
                </div>
            </div>
        </div>
    );
}
