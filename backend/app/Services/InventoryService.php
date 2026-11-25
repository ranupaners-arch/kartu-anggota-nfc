<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryLoan;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inventaris. Barang dikelola Super Admin; Super Admin & Admin Divisi mencatat pinjam dan kembali. Baris barang
 * dikunci saat stok berubah, jadi barang tidak bisa dipinjamkan melebihi stok.
 */
class InventoryService
{
    public function saveItem(User $by, array $d, ?InventoryItem $item = null): InventoryItem
    {
        $this->assertManage($by);

        return DB::transaction(function () use ($by, $d, $item) {
            if ($item) {
                $item = InventoryItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
                if ($d['quantity'] < ($out = $item->onLoan())) {
                    throw ValidationException::withMessages(['quantity' => "Sedang dipinjam $out; jumlah tidak boleh kurang dari itu."]);
                }
                $old = $item->quantity;
                $item->update($d);
                AuditLogger::log('inventory.updated', 'inventory_item', $item->id, ['from' => $old, 'to' => $item->quantity], $by->id);
            } else {
                $item = InventoryItem::create($d + ['organization_id' => $by->organization_id]);
                AuditLogger::log('inventory.created', 'inventory_item', $item->id, ['quantity' => $item->quantity], $by->id);
            }

            return $item;
        });
    }

    public function setArchived(User $by, InventoryItem $item, bool $archived): void
    {
        $this->assertManage($by);
        DB::transaction(function () use ($by, $item, $archived) {
            $i = InventoryItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_if($archived && $i->onLoan() > 0, 422, 'Barang ini masih dipinjam.');
            $i->update(['archived_at' => $archived ? now() : null]);
            AuditLogger::log($archived ? 'inventory.archived' : 'inventory.restored', 'inventory_item', $i->id, [], $by->id);
        });
    }

    public function deleteItem(User $by, InventoryItem $item): void
    {
        $this->assertManage($by);
        DB::transaction(function () use ($by, $item) {
            $i = InventoryItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_if($i->loans()->exists(), 422, 'Barang ini punya riwayat peminjaman; arsipkan saja.');
            $i->delete();
            AuditLogger::log('inventory.deleted', 'inventory_item', $i->id, ['name' => $i->name], $by->id);
        });
    }

    public function lend(User $by, InventoryItem $item, string $memberNo, int $quantity, string $dueOn, ?string $note): InventoryLoan
    {
        $this->assertLend($by);
        $member = Member::where('member_no', trim($memberNo))->first()
            ?? throw ValidationException::withMessages(['member_no' => 'Nomor anggota tidak ditemukan.']);

        return DB::transaction(function () use ($by, $item, $member, $quantity, $dueOn, $note) {
            $i = InventoryItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($i->archived_at) {
                throw ValidationException::withMessages(['item_id' => 'Barang ini diarsipkan.']);
            }
            if ($quantity > ($free = $i->quantity - $i->onLoan())) {
                throw ValidationException::withMessages(['quantity' => "Tersedia $free."]);
            }
            $l = InventoryLoan::create(['item_id' => $i->id, 'member_id' => $member->id, 'quantity' => $quantity, 'due_on' => $dueOn, 'note' => $note, 'lent_by' => $by->id]);
            AuditLogger::log('inventory.lent', 'inventory_item', $i->id, ['loan_id' => $l->id, 'quantity' => $quantity], $by->id);

            return $l;
        });
    }

    /** Barang hilang mengurangi jumlah barang. */
    public function giveBack(User $by, InventoryLoan $loan, string $condition, ?string $note): void
    {
        $this->assertLend($by);
        if ($condition !== 'good' && mb_strlen(trim((string) $note)) < 3) {
            throw ValidationException::withMessages(['note' => 'Keterangan wajib diisi bila rusak atau hilang (min. 3 karakter).']);
        }
        DB::transaction(function () use ($by, $loan, $condition, $note) {
            $i = InventoryItem::whereKey($loan->item_id)->lockForUpdate()->firstOrFail();
            $l = InventoryLoan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            abort_if($l->returned_at, 422, 'Peminjaman ini sudah selesai.');
            $l->update(['returned_at' => now(), 'condition' => $condition, 'return_note' => $note, 'received_by' => $by->id]);
            if ($condition === 'lost') {
                $i->decrement('quantity', $l->quantity);
            }
            AuditLogger::log('inventory.returned', 'inventory_item', $i->id, ['loan_id' => $l->id, 'condition' => $condition], $by->id);
        });
    }

    /** Barang yang sedang dipinjam anggota, terdekat jatuh temponya dulu. */
    public function activeFor(int $memberId): Collection
    {
        return InventoryLoan::with('item:id,name,organization_id')->where('member_id', $memberId)->whereNull('returned_at')->orderBy('due_on')->orderBy('id')->get();
    }

    public function assertManage(User $by): void
    {
        abort_unless($by->isSuperAdmin(), 403, 'Hanya Super Admin yang mengelola barang.');
    }

    private function assertLend(User $by): void
    {
        abort_unless($by->isAdmin(), 403);
    }
}
