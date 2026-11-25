<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Services\AuditLogger;
use App\Services\CashService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class CashController extends Controller
{
    public function __construct(private CashService $cash) {}

    public function index(Request $r)
    {
        $f = $this->filters($r);
        $tab = in_array($r->query('tab'), ['laporan', 'kategori'], true) ? $r->query('tab') : 'transaksi';

        return Inertia::render('cash/index', [
            'tab' => $tab, 'balance' => $this->cash->balance(), 'canManage' => $r->user()->canManageDues(),
            'filters' => $f + ['q' => null, 'kind' => null, 'category' => null, 'from' => null, 'to' => null],
            'categories' => CashCategory::withCount('entries')->orderBy('kind')->orderBy('name')->get(['id', 'kind', 'name', 'is_dues']),
            'entries' => $tab === 'transaksi' ? $this->cash->query($f)->with(['category:id,name', 'creator:id,name', 'voider:id,name', 'dueItem.member:id,name'])->latest('occurred_on')->latest('id')
                ->paginate(50)->withQueryString()->through(fn (CashEntry $e) => [
                    'id' => $e->id, 'kind' => $e->kind, 'amount' => $e->amount, 'date' => $e->occurred_on->toDateString(), 'description' => $e->description,
                    'member' => $e->dueItem?->member?->name, 'is_due' => $e->due_item_id !== null, 'category' => $e->category?->name, 'has_receipt' => $e->receipt_path !== null,
                    'by' => $e->creator?->name, 'voided_at' => $e->voided_at, 'voided_by' => $e->voider?->name, 'void_reason' => $e->void_reason,
                ]) : null,
            'report' => $tab === 'laporan' ? $this->cash->report($f['from'] ?? null, $f['to'] ?? null) : null,
        ]);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'kind' => 'required|in:in,out', 'category_id' => 'required|integer', 'amount' => 'required|integer|min:1|max:10000000000',
            'occurred_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'description' => 'required|string|min:3|max:200',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);
        $this->cash->record($r->user(), $d, $r->file('receipt'));

        return back()->with('status', $d['kind'] === 'in' ? 'Uang masuk dicatat.' : 'Uang keluar dicatat.');
    }

    public function void(Request $r, CashEntry $entry)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:300']);
        $this->cash->void($r->user(), $entry, $d['reason']);

        return back()->with('status', 'Transaksi dibatalkan. Catatannya tetap tersimpan.');
    }

    /** Bukti disimpan privat: hanya yang boleh melihat kas. */
    public function receipt(CashEntry $entry)
    {
        abort_unless($entry->receipt_path && Storage::disk('local')->exists($entry->receipt_path), 404);

        return Storage::disk('local')->response($entry->receipt_path, headers: ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function export(Request $r)
    {
        $safe = fn (?string $v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'$v" : (string) $v;
        $q = $this->cash->query($this->filters($r))->with(['category:id,name', 'creator:id,name', 'voider:id,name', 'dueItem.member:id,name'])->orderBy('occurred_on')->orderBy('id');

        return response()->streamDownload(function () use ($q, $safe) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['No', 'Tanggal', 'Jenis', 'Kategori', 'Keterangan', 'Anggota', 'Masuk', 'Keluar', 'Status', 'Alasan batal', 'Dicatat oleh', 'Dibatalkan oleh'], escape: '');
            foreach ($q->lazy(500) as $e) {
                fputcsv($out, [$e->id, $e->occurred_on->toDateString(), $e->kind === 'in' ? 'Masuk' : 'Keluar', $safe($e->category?->name), $safe($e->description), $safe($e->dueItem?->member?->name),
                    $e->kind === 'in' ? $e->amount : '', $e->kind === 'out' ? $e->amount : '', $e->voided_at ? 'Dibatalkan' : 'Berlaku', $safe($e->void_reason), $safe($e->creator?->name), $safe($e->voider?->name)], escape: '');
            }
            fclose($out);
        }, 'kas.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function storeCategory(Request $r)
    {
        $this->cash->assertManage($r->user());
        $d = $this->categoryInput($r);
        $c = CashCategory::create($d + ['organization_id' => $r->user()->organization_id]);
        AuditLogger::log('cash_category.created', 'cash_category', $c->id, $d);

        return back()->with('status', 'Kategori ditambahkan.');
    }

    public function updateCategory(Request $r, CashCategory $category)
    {
        $this->cash->assertManage($r->user());
        $r->merge(['kind' => $category->kind]);
        $category->update(['name' => $this->categoryInput($r, $category)['name']]);
        AuditLogger::log('cash_category.updated', 'cash_category', $category->id, ['name' => $category->name]);

        return back()->with('status', 'Kategori diganti nama.');
    }

    public function destroyCategory(Request $r, CashCategory $category)
    {
        $this->cash->assertManage($r->user());
        abort_if($category->is_dues, 422, 'Kategori iuran dipakai otomatis dan tidak bisa dihapus.');
        abort_if($category->entries()->exists(), 422, "Kategori {$category->name} sudah dipakai transaksi; ganti namanya saja.");
        abort_if(CashCategory::where('kind', $category->kind)->where('is_dues', false)->count() <= 1, 422, 'Minimal satu kategori per jenis.');
        $category->delete();
        AuditLogger::log('cash_category.deleted', 'cash_category', $category->id, ['name' => $category->name]);

        return back()->with('status', 'Kategori dihapus.');
    }

    private function categoryInput(Request $r, ?CashCategory $category = null): array
    {
        $r->merge(['name' => trim((string) $r->input('name'))]);

        return $r->validate(['kind' => 'required|in:in,out', 'name' => ['required', 'string', 'max:50', function ($a, $value, $fail) use ($r, $category) {
            if (CashCategory::where('kind', $r->input('kind'))->whereRaw('lower(name) = ?', [mb_strtolower($value)])->when($category, fn ($q) => $q->whereKeyNot($category->id))->exists()) {
                $fail('Kategori ini sudah ada.');
            }
        }]]);
    }

    private function filters(Request $r): array
    {
        $f = $r->validate(['q' => 'nullable|string|max:100', 'kind' => 'nullable|in:in,out', 'category' => 'nullable|integer', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);

        return array_filter($f, fn ($v) => $v !== null && $v !== '');
    }
}
