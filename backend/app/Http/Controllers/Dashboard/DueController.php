<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Due;
use App\Models\DueItem;
use App\Models\DueItemChange;
use App\Models\Term;
use App\Services\DuesService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Iuran: daftar tagihan, pelunasan (Super Admin & bendahara), riwayat audit (juga sekretaris). */
class DueController extends Controller
{
    public function __construct(private DuesService $dues) {}

    public function index(Request $r)
    {
        $term = Term::where('is_current', true)->first();

        return Inertia::render('dues/index', [
            'dues' => Due::with('term:id,name')->latest('id')->paginate(20)->through(fn (Due $d) => $d->only('id', 'title', 'amount', 'due_on', 'cancelled_at')
                + ['due_date' => $d->due_on?->toDateString(), 'term' => $d->term?->name, 'summary' => $this->dues->summary($d)]),
            'term' => $term?->name,
            'canManage' => $r->user()->canManageDues(),
        ]);
    }

    public function store(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:100', 'amount' => 'required|integer|min:1|max:10000000', 'due_on' => 'nullable|date']);
        $due = $this->dues->create($r->user(), $d);

        return redirect()->route('dues.show', $due)->with('status', 'Tagihan dibuat untuk semua anggota aktif.');
    }

    public function show(Request $r, Due $due)
    {
        $tab = $r->query('tab') === 'riwayat' ? 'riwayat' : 'tagihan';
        $f = ['q' => $r->query('q'), 'status' => $r->query('status', 'semua')];

        return Inertia::render('dues/show', [
            'due' => $due->only('id', 'title', 'amount', 'cancelled_at', 'cancel_reason') + ['due_date' => $due->due_on?->toDateString()],
            'summary' => $this->dues->summary($due),
            'tab' => $tab, 'filters' => $f, 'canManage' => $r->user()->canManageDues(),
            'items' => $tab === 'tagihan' ? $this->items($due, $f)->paginate(50)->withQueryString()->through(fn (DueItem $i) => [
                'id' => $i->id, 'status' => $i->status, 'member' => $i->member?->only('member_no', 'name'),
                'note' => $i->lastChange?->note, 'changed_by' => $i->lastChange?->changer?->name, 'changed_at' => $i->lastChange?->created_at,
            ]) : null,
            'changes' => $tab === 'riwayat' ? DueItemChange::with(['item.member:id,member_no,name', 'changer:id,name'])
                ->whereHas('item', fn ($q) => $q->where('due_id', $due->id))->latest('id')->paginate(50)->withQueryString()->through(fn (DueItemChange $c) => [
                    'id' => $c->id, 'from' => $c->from_status, 'to' => $c->to_status, 'note' => $c->note, 'at' => $c->created_at,
                    'by' => $c->changer?->name, 'member' => $c->item?->member?->only('member_no', 'name'),
                ]) : null,
        ]);
    }

    public function mark(Request $r, Due $due)
    {
        $d = $r->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer', 'status' => 'required|in:unpaid,paid,waived', 'note' => 'nullable|string|max:300']);
        $n = $this->dues->mark($r->user(), $due, $d['ids'], $d['status'], $d['note'] ?? null);

        return back()->with('status', "$n tagihan ".['paid' => 'ditandai lunas.', 'waived' => 'dibebaskan.', 'unpaid' => 'dikembalikan ke belum lunas.'][$d['status']]);
    }

    public function sync(Request $r, Due $due)
    {
        return back()->with('status', $this->dues->syncMembers($r->user(), $due).' anggota ditambahkan.');
    }

    public function cancel(Request $r, Due $due)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:300']);
        $this->dues->cancel($r->user(), $due, $d['reason']);

        return back()->with('status', 'Tagihan dibatalkan.');
    }

    /** CSV semua anggota di tagihan ini (untuk audit & arsip). */
    public function export(Due $due)
    {
        $label = ['unpaid' => 'Belum lunas', 'paid' => 'Lunas', 'waived' => 'Dibebaskan'];
        // Awalan = + - @ dianggap rumus oleh aplikasi spreadsheet.
        $safe = fn (?string $v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'$v" : (string) $v;

        return response()->streamDownload(function () use ($due, $label, $safe) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['No. Anggota', 'Nama', 'Tagihan', 'Nominal', 'Status', 'Catatan', 'Dicatat oleh', 'Waktu'], escape: '');
            foreach ($this->items($due, ['q' => null, 'status' => 'semua'])->get() as $i) {
                $c = $i->lastChange;
                fputcsv($out, [$safe($i->member?->member_no), $safe($i->member?->name), $safe($due->title), $due->amount, $label[$i->status],
                    $safe($c?->note), $safe($c?->changer?->name), $c?->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i')], escape: '');
            }
            fclose($out);
        }, "iuran-{$due->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function items(Due $due, array $f)
    {
        return DueItem::with(['member:id,member_no,name', 'lastChange.changer:id,name'])->where('due_id', $due->id)
            ->when($f['status'] !== 'semua', fn ($q) => $q->where('status', $f['status']))
            ->when($f['q'], fn ($q, $s) => $q->whereHas('member', fn ($m) => $m->where(fn ($w) => $w->where('name', 'ilike', "%$s%")->orWhere('member_no', 'ilike', "%$s%"))))
            ->join('members', 'members.id', '=', 'due_items.member_id')->orderBy('members.name')->orderBy('due_items.id')->select('due_items.*');
    }
}
