<?php

namespace App\Services;

use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\DueItem;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Buku kas. Semua pencatatan mengunci baris organisasi, jadi saldo tidak bisa minus karena dua pengeluaran
 * bersamaan. Hak: sama dengan iuran (mencatat: Super Admin & bendahara; sekretaris hanya melihat).
 */
class CashService
{
    public function record(User $by, array $d, ?UploadedFile $receipt = null): CashEntry
    {
        $this->assertManage($by);
        $category = CashCategory::findOrFail($d['category_id']);
        if ($category->kind !== $d['kind']) {
            throw ValidationException::withMessages(['category_id' => 'Kategori tidak sesuai jenis transaksi.']);
        }
        if ($category->is_dues) {
            throw ValidationException::withMessages(['category_id' => 'Kategori ini diisi otomatis saat iuran ditandai lunas.']);
        }
        $term = Term::where('is_current', true)->first() ?? throw ValidationException::withMessages(['kind' => 'Belum ada periode berjalan.']);
        $path = $receipt?->store("cash/{$by->organization_id}", 'local');

        try {
            return DB::transaction(function () use ($by, $d, $category, $term, $path) {
                $this->lock($by->organization_id);
                if ($d['kind'] === CashEntry::OUT && $d['amount'] > ($balance = $this->balance())) {
                    throw ValidationException::withMessages(['amount' => 'Melebihi saldo kas (Rp'.number_format($balance, 0, ',', '.').').']);
                }
                $e = CashEntry::create(['organization_id' => $by->organization_id, 'term_id' => $term->id, 'category_id' => $category->id, 'kind' => $d['kind'],
                    'amount' => $d['amount'], 'occurred_on' => $d['occurred_on'], 'description' => $d['description'], 'receipt_path' => $path, 'created_by' => $by->id]);
                AuditLogger::log('cash.recorded', 'cash_entry', $e->id, ['kind' => $e->kind, 'amount' => $e->amount], $by->id);

                return $e;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path); // transaksi batal: bukti tidak boleh tertinggal
            }
            throw $e;
        }
    }

    public function void(User $by, CashEntry $entry, string $reason): void
    {
        $this->assertManage($by);
        DB::transaction(function () use ($by, $entry, $reason) {
            $this->lock($by->organization_id);
            $e = CashEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            abort_if($e->voided_at, 422, 'Transaksi ini sudah dibatalkan.');
            if ($e->due_item_id) {
                throw ValidationException::withMessages(['reason' => 'Transaksi iuran dibatalkan dari halaman Iuran (kembalikan ke belum lunas).']);
            }
            if ($e->kind === CashEntry::IN && $this->balance() - $e->amount < 0) {
                throw ValidationException::withMessages(['reason' => 'Uang ini sudah terpakai: membatalkannya membuat saldo minus. Batalkan pengeluarannya dulu.']);
            }
            $this->markVoid($e, $by, $reason);
        });
    }

    /** Iuran ditandai lunas → uang masuk; dikembalikan → catatannya dibatalkan. Dipanggil di dalam transaksi iuran. */
    public function onDueItem(DueItem $item, string $from, string $to, User $by, ?string $note): void
    {
        if ($to === DueItem::PAID) {
            $this->lock($by->organization_id);
            $category = CashCategory::where('is_dues', true)->firstOrFail();
            CashEntry::create(['organization_id' => $by->organization_id, 'term_id' => $item->due->term_id, 'category_id' => $category->id, 'kind' => CashEntry::IN,
                'amount' => $item->due->amount, 'occurred_on' => today()->toDateString(), 'description' => $item->due->title, 'due_item_id' => $item->id, 'created_by' => $by->id]);
        } elseif ($from === DueItem::PAID) {
            $this->lock($by->organization_id);
            $e = CashEntry::where('due_item_id', $item->id)->whereNull('voided_at')->first();
            if ($e && $this->balance() - $e->amount < 0) {
                throw ValidationException::withMessages(['note' => 'Uang iuran ini sudah terpakai: mengembalikannya membuat saldo kas minus.']);
            }
            if ($e) {
                $this->markVoid($e, $by, (string) $note);
            }
        }
    }

    /** Saldo semua periode; $before = sampai sebelum tanggal itu. */
    public function balance(?string $before = null): int
    {
        $r = CashEntry::whereNull('voided_at')->when($before, fn ($q) => $q->whereDate('occurred_on', '<', $before))
            ->selectRaw("coalesce(sum(case when kind = 'in' then amount else 0 end), 0) as masuk, coalesce(sum(case when kind = 'out' then amount else 0 end), 0) as keluar")->toBase()->first();

        return (int) $r->masuk - (int) $r->keluar;
    }

    public function query(array $f): Builder
    {
        return CashEntry::query()
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('occurred_on', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('occurred_on', '<=', $d))
            ->when($f['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category_id', (int) $c))
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where('description', 'ilike', "%$s%"));
    }

    /** Ringkasan rentang tanggal: saldo awal, masuk, keluar, saldo akhir, rincian per kategori. */
    public function report(?string $from, ?string $to): array
    {
        $rows = $this->query(['from' => $from, 'to' => $to])->whereNull('voided_at')
            ->join('cash_categories', 'cash_categories.id', '=', 'cash_entries.category_id')
            ->groupBy('cash_entries.kind', 'cash_categories.name')->orderBy('cash_entries.kind')->orderByRaw('sum(amount) desc')
            ->toBase()->get([DB::raw('cash_entries.kind as kind'), 'cash_categories.name', DB::raw('sum(amount) as total'), DB::raw('count(*) as n')]);
        $sum = fn (string $k) => (int) $rows->where('kind', $k)->sum(fn ($r) => (int) $r->total);
        $opening = $from ? $this->balance($from) : 0;

        return ['opening' => $opening, 'in' => $sum('in'), 'out' => $sum('out'), 'closing' => $opening + $sum('in') - $sum('out'),
            'categories' => $rows->map(fn ($r) => ['kind' => $r->kind, 'name' => $r->name, 'total' => (int) $r->total, 'count' => (int) $r->n])->values()];
    }

    private function markVoid(CashEntry $e, User $by, string $reason): void
    {
        $e->update(['voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => $reason]);
        AuditLogger::log('cash.voided', 'cash_entry', $e->id, ['reason' => $reason, 'amount' => $e->amount], $by->id);
    }

    private function lock(int $organizationId): void
    {
        Organization::whereKey($organizationId)->lockForUpdate()->first();
    }

    public function assertManage(User $by): void
    {
        abort_unless($by->canManageDues(), 403, 'Hanya Super Admin dan bendahara yang bisa mencatat kas.');
    }
}
