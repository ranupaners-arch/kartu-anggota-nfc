<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Fine;
use App\Models\Term;
use App\Services\FineService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Denda absen pakai QR: daftar, total belum lunas, tandai lunas/dibebaskan (Super Admin). */
class FineController extends Controller
{
    public function index(Request $r)
    {
        $term = Term::where('is_current', true)->first();
        $status = $r->query('status', Fine::UNPAID);
        $q = Fine::with(['member:id,member_no,name', 'attendance.event:id,title,starts_at', 'handler:id,name'])
            ->when($term, fn ($q) => $q->where('term_id', $term->id))
            ->where('status', '!=', Fine::FREE)
            ->when($status !== 'semua', fn ($q) => $q->where('status', $status))
            ->when($r->query('q'), fn ($q, $s) => $q->whereHas('member', fn ($m) => $m->where('name', 'ilike', "%$s%")->orWhere('member_no', 'ilike', "%$s%")))
            ->latest();
        $base = Fine::when($term, fn ($q) => $q->where('term_id', $term->id));

        return Inertia::render('fines/index', [
            'fines' => $q->paginate(30)->withQueryString()->through(fn (Fine $f) => [
                'id' => $f->id, 'amount' => $f->amount, 'status' => $f->status, 'note' => $f->note, 'handled_at' => $f->handled_at,
                'handler' => $f->handler?->name, 'member' => $f->member?->only('id', 'member_no', 'name'),
                'event' => $f->attendance?->event?->only('id', 'title', 'starts_at'),
            ]),
            'summary' => [
                'unpaid_total' => (int) (clone $base)->where('status', Fine::UNPAID)->sum('amount'),
                'unpaid_count' => (clone $base)->where('status', Fine::UNPAID)->count(),
                'paid_total' => (int) (clone $base)->where('status', Fine::PAID)->sum('amount'),
            ],
            'policy' => $r->user()->organization->only('fine_enabled', 'fine_amount', 'fine_free_per_term'),
            'term' => $term?->name,
            'filters' => ['status' => $status, 'q' => $r->query('q')],
        ]);
    }

    public function mark(Request $r, FineService $fines)
    {
        $d = $r->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer', 'status' => 'required|in:paid,waived,unpaid', 'note' => 'nullable|string|max:300']);
        $n = $fines->mark($r->user(), $d['ids'], $d['status'], $d['note'] ?? null);

        return back()->with('status', "$n denda ".['paid' => 'ditandai lunas.', 'waived' => 'dibebaskan.', 'unpaid' => 'dikembalikan ke belum lunas.'][$d['status']]);
    }
}
