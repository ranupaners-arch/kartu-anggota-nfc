<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryLoan;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(Request $r)
    {
        $tab = $r->query('tab') === 'pinjaman' ? 'pinjaman' : 'barang';
        $f = $r->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:aktif,terlambat,selesai,arsip']);
        $f = ['q' => $f['q'] ?? null, 'status' => $f['status'] ?? null];
        $like = $f['q'] ? "%{$f['q']}%" : null;

        return Inertia::render('inventory/index', [
            'tab' => $tab, 'filters' => $f, 'canManage' => $r->user()->isSuperAdmin(),
            'overdue' => InventoryLoan::whereNull('returned_at')->whereDate('due_on', '<', today())->count(),
            'items' => $tab === 'barang' ? InventoryItem::withSum(['loans as on_loan' => fn ($q) => $q->whereNull('returned_at')], 'quantity')->withExists('loans')
                ->when($f['status'] === 'arsip', fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
                ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like)->orWhere('location', 'ilike', $like)))
                ->orderBy('name')->orderBy('id')->paginate(30)->withQueryString()
                ->through(fn (InventoryItem $i) => $i->only('id', 'name', 'code', 'quantity', 'location', 'note', 'archived_at') + ['on_loan' => (int) $i->on_loan, 'has_loans' => (bool) $i->loans_exists]) : null,
            'available' => $tab === 'pinjaman' ? InventoryItem::withSum(['loans as on_loan' => fn ($q) => $q->whereNull('returned_at')], 'quantity')->whereNull('archived_at')->orderBy('name')->get()
                ->map(fn (InventoryItem $i) => ['id' => $i->id, 'name' => $i->code ? "$i->name ($i->code)" : $i->name, 'free' => $i->quantity - (int) $i->on_loan])->filter(fn ($i) => $i['free'] > 0)->values() : null,
            'loans' => $tab === 'pinjaman' ? $this->loans($f, $like)->paginate(30)->withQueryString()->through(fn (InventoryLoan $l) => [
                'id' => $l->id, 'item' => $l->item->name, 'quantity' => $l->quantity, 'member' => $l->member?->only('id', 'member_no', 'name'), 'lent_at' => $l->created_at, 'due_on' => $l->due_on->toDateString(),
                'overdue' => $l->isOverdue(), 'note' => $l->note, 'lender' => $l->lender?->name, 'returned_at' => $l->returned_at, 'condition' => $l->condition, 'return_note' => $l->return_note, 'receiver' => $l->receiver?->name,
            ]) : null,
        ]);
    }

    private function loans(array $f, ?string $like)
    {
        return InventoryLoan::with(['item:id,name,organization_id', 'member:id,member_no,name,deleted_at', 'lender:id,name', 'receiver:id,name'])
            ->when($f['status'] === 'aktif', fn ($q) => $q->whereNull('returned_at'))
            ->when($f['status'] === 'terlambat', fn ($q) => $q->whereNull('returned_at')->whereDate('due_on', '<', today()))
            ->when($f['status'] === 'selesai', fn ($q) => $q->whereNotNull('returned_at'))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->whereHas('item', fn ($i) => $i->where('name', 'ilike', $like))->orWhereHas('member', fn ($m) => $m->where('name', 'ilike', $like)->orWhere('member_no', 'ilike', $like))))
            ->orderByRaw('returned_at is not null')->orderByRaw('case when returned_at is null then due_on end')->latest('returned_at')->latest('id');
    }

    public function store(Request $r)
    {
        $this->inventory->assertManage($r->user());
        $this->inventory->saveItem($r->user(), $this->itemInput($r));

        return back()->with('status', 'Barang ditambahkan.');
    }

    public function update(Request $r, InventoryItem $item)
    {
        $this->inventory->assertManage($r->user());
        $this->inventory->saveItem($r->user(), $this->itemInput($r, $item), $item);

        return back()->with('status', 'Barang diperbarui.');
    }

    public function archive(Request $r, InventoryItem $item)
    {
        $d = $r->validate(['archived' => 'required|boolean']);
        $this->inventory->setArchived($r->user(), $item, (bool) $d['archived']);

        return back()->with('status', $d['archived'] ? 'Barang diarsipkan.' : 'Barang dipulihkan.');
    }

    public function destroy(Request $r, InventoryItem $item)
    {
        $this->inventory->deleteItem($r->user(), $item);

        return back()->with('status', 'Barang dihapus.');
    }

    public function lend(Request $r)
    {
        $d = $r->validate(['item_id' => 'required|integer', 'member_no' => 'required|string|max:50', 'quantity' => 'required|integer|min:1|max:100000',
            'due_on' => 'required|date_format:Y-m-d|after_or_equal:today', 'note' => 'nullable|string|max:300']);
        $this->inventory->lend($r->user(), InventoryItem::findOrFail($d['item_id']), $d['member_no'], (int) $d['quantity'], $d['due_on'], $d['note'] ?? null);

        return back()->with('status', 'Peminjaman dicatat.');
    }

    public function giveBack(Request $r, InventoryLoan $loan)
    {
        $d = $r->validate(['condition' => 'required|in:'.implode(',', InventoryLoan::CONDITIONS), 'note' => 'nullable|string|max:300']);
        $this->inventory->giveBack($r->user(), $loan, $d['condition'], $d['note'] ?? null);

        return back()->with('status', 'Pengembalian dicatat.');
    }

    public function export(Request $r)
    {
        $safe = fn (?string $v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'$v" : (string) $v;
        $time = fn ($t) => $t?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '';
        $q = $this->loans(['status' => null], null);

        return response()->streamDownload(function () use ($q, $safe, $time) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Barang', 'Jumlah', 'No. Anggota', 'Peminjam', 'Dipinjam', 'Jatuh tempo', 'Kembali', 'Kondisi', 'Keterangan', 'Dicatat oleh', 'Diterima oleh'], escape: '');
            foreach ($q->lazy(500) as $l) {
                fputcsv($out, [$safe($l->item->name), $l->quantity, $safe($l->member?->member_no), $safe($l->member?->name), $time($l->created_at), $l->due_on->toDateString(), $time($l->returned_at),
                    ['good' => 'Baik', 'damaged' => 'Rusak', 'lost' => 'Hilang'][$l->condition] ?? 'Dipinjam', $safe(implode(' / ', array_filter([$l->note, $l->return_note]))), $safe($l->lender?->name), $safe($l->receiver?->name)], escape: '');
            }
            fclose($out);
        }, 'peminjaman.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function itemInput(Request $r, ?InventoryItem $item = null): array
    {
        $r->merge(['code' => trim((string) $r->input('code')) ?: null]);

        return $r->validate([
            'name' => 'required|string|max:100', 'quantity' => 'required|integer|min:0|max:100000', 'location' => 'nullable|string|max:100', 'note' => 'nullable|string|max:300',
            'code' => ['nullable', 'string', 'max:30', function ($a, $value, $fail) use ($item) {
                if (InventoryItem::whereRaw('lower(code) = ?', [mb_strtolower((string) $value)])->when($item, fn ($q) => $q->whereKeyNot($item->id))->exists()) {
                    $fail('Kode ini sudah dipakai.');
                }
            }],
        ]);
    }
}
