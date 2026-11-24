<?php

namespace App\Services;

use App\Models\Due;
use App\Models\DueItem;
use App\Models\Member;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Iuran: tagihan, pelunasan manual, dan riwayat audit. Pembayaran digital di luar lingkup (PRD §4). */
class DuesService
{
    public const LABELS = [User::TREASURER => 'Bendahara', User::AUDITOR => 'Sekretaris'];

    public function create(User $by, array $d): Due
    {
        $this->assertManage($by);
        $term = Term::where('is_current', true)->first() ?? throw ValidationException::withMessages(['title' => 'Belum ada periode berjalan.']);

        return DB::transaction(function () use ($by, $d, $term) {
            // Kunci periode: dua permintaan bersamaan tidak membuat tagihan berjudul sama.
            Term::whereKey($term->id)->lockForUpdate()->first();
            if (Due::where('term_id', $term->id)->whereNull('cancelled_at')->whereRaw('lower(title) = ?', [mb_strtolower($d['title'])])->exists()) {
                throw ValidationException::withMessages(['title' => 'Tagihan dengan judul ini sudah ada di periode berjalan.']);
            }
            $due = Due::create(['organization_id' => $by->organization_id, 'term_id' => $term->id, 'title' => $d['title'],
                'amount' => $d['amount'], 'due_on' => $d['due_on'] ?? null, 'created_by' => $by->id]);
            $n = $this->addMembers($due);
            AuditLogger::log('due.created', 'due', $due->id, ['amount' => $due->amount, 'members' => $n], $by->id);

            return $due;
        });
    }

    /** Tambahkan anggota aktif yang belum punya tagihan ini (mis. baru disahkan). */
    public function syncMembers(User $by, Due $due): int
    {
        $this->assertManage($by);

        return DB::transaction(function () use ($by, $due) {
            $due = $this->locked($due);
            $n = $this->addMembers($due);
            AuditLogger::log('due.members_added', 'due', $due->id, ['members' => $n], $by->id);

            return $n;
        });
    }

    /** Tandai banyak tagihan. Membebaskan atau membatalkan tanda lunas/bebas wajib beralasan. */
    public function mark(User $by, Due $due, array $ids, string $status, ?string $note): int
    {
        $this->assertManage($by);
        abort_unless(in_array($status, [DueItem::UNPAID, DueItem::PAID, DueItem::WAIVED], true), 422);

        return DB::transaction(function () use ($by, $due, $ids, $status, $note) {
            $due = $this->locked($due);
            $items = DueItem::where('due_id', $due->id)->whereIn('id', $ids)->where('status', '!=', $status)->orderBy('id')->lockForUpdate()->get();

            if (! $by->isSuperAdmin() && $by->member_id && $items->contains('member_id', $by->member_id)) {
                throw ValidationException::withMessages(['ids' => 'Iuran Anda sendiri harus dicatat Super Admin atau bendahara lain.']);
            }
            $needsReason = $status === DueItem::WAIVED || $items->contains(fn (DueItem $i) => $i->status !== DueItem::UNPAID);
            if ($needsReason && mb_strlen(trim((string) $note)) < 3) {
                throw ValidationException::withMessages(['note' => 'Alasan wajib diisi (min. 3 karakter).']);
            }
            foreach ($items as $i) {
                $i->changes()->create(['from_status' => $i->status, 'to_status' => $status, 'note' => $note, 'changed_by' => $by->id]);
                $i->update(['status' => $status]);
            }
            if ($items->isNotEmpty()) {
                AuditLogger::log("due.$status", 'due', $due->id, ['item_ids' => $items->pluck('id')->all(), 'note' => $note], $by->id);
            }

            return $items->count();
        });
    }

    /** Batalkan tagihan yang salah; tidak bisa bila sudah ada yang lunas. */
    public function cancel(User $by, Due $due, string $reason): void
    {
        $this->assertManage($by);
        DB::transaction(function () use ($by, $due, $reason) {
            $due = $this->locked($due);
            if ($due->items()->where('status', DueItem::PAID)->exists()) {
                throw ValidationException::withMessages(['reason' => 'Sudah ada yang lunas; kembalikan dulu statusnya sebelum membatalkan tagihan.']);
            }
            $due->update(['cancelled_at' => now(), 'cancel_reason' => $reason]);
            AuditLogger::log('due.cancelled', 'due', $due->id, ['reason' => $reason], $by->id);
        });
    }

    /** @return array{unpaid: int, paid: int, waived: int, paid_total: int, unpaid_total: int} */
    public function summary(Due $due): array
    {
        $by = $due->items()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $n = fn (string $s) => (int) ($by[$s] ?? 0);

        return ['unpaid' => $n('unpaid'), 'paid' => $n('paid'), 'waived' => $n('waived'),
            'paid_total' => $n('paid') * $due->amount, 'unpaid_total' => $n('unpaid') * $due->amount];
    }

    /** Ringkasan untuk anggota sendiri (semua periode yang belum lunas + periode ini). */
    public function forMember(int $memberId, ?int $termId): array
    {
        $items = DueItem::with('due')->where('member_id', $memberId)
            ->whereHas('due', fn ($q) => $q->whereNull('cancelled_at'))
            ->where(fn ($q) => $q->where('status', DueItem::UNPAID)->when($termId, fn ($q) => $q->orWhereHas('due', fn ($d) => $d->where('term_id', $termId))))
            ->latest('id')->get();

        return [
            'unpaid_total' => (int) $items->where('status', DueItem::UNPAID)->sum(fn (DueItem $i) => $i->due->amount),
            'items' => $items->take(30)->map(fn (DueItem $i) => ['id' => $i->id, 'title' => $i->due->title, 'amount' => $i->due->amount,
                'status' => $i->status, 'due_on' => $i->due->due_on?->toDateString()])->values(),
        ];
    }

    private function addMembers(Due $due): int
    {
        $ids = Member::whereHas('memberships', fn ($q) => $q->where('term_id', $due->term_id)->where('status', 'active'))
            ->whereNotIn('id', DueItem::where('due_id', $due->id)->select('member_id'))->pluck('id');
        foreach ($ids->chunk(500) as $chunk) {
            DueItem::insert($chunk->map(fn ($id) => ['due_id' => $due->id, 'member_id' => $id, 'status' => DueItem::UNPAID, 'created_at' => now(), 'updated_at' => now()])->all());
        }

        return $ids->count();
    }

    private function locked(Due $due): Due
    {
        $due = Due::whereKey($due->id)->lockForUpdate()->firstOrFail();
        abort_if($due->cancelled_at, 422, 'Tagihan ini sudah dibatalkan.');

        return $due;
    }

    private function assertManage(User $by): void
    {
        abort_unless($by->canManageDues(), 403, 'Hanya Super Admin dan bendahara yang bisa mencatat iuran.');
    }
}
