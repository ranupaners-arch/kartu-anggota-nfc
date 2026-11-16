<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\Export;
use App\Models\Term;
use App\Services\ExportService;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    public function __construct(private ExportService $exports) {}

    public function index(Request $r, ReportService $reports)
    {
        $user = $r->user();
        $tab = $r->query('tab', $user->isSuperAdmin() ? 'periode' : 'divisi');
        $terms = Term::orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', (int) $r->query('term_id')) ?? $terms->firstWhere('is_current', true) ?? $terms->first();
        $divisionId = $tab === 'divisi' ? $this->exports->divisionScope($user, $r->integer('division_id') ?: null) : null;

        return view('reports.index', [
            'tab' => $tab, 'terms' => $terms, 'term' => $term, 'divisionId' => $divisionId,
            'divisions' => $user->isSuperAdmin() ? Division::orderBy('name')->get() : Division::whereKey($user->division_id)->get(),
            'report' => $term && $tab !== 'ekspor' && ($tab === 'periode' || $divisionId) ? $reports->term($term, $divisionId) : null,
            'exports' => $tab === 'ekspor'
                ? Export::query()->when(! $user->isSuperAdmin(), fn ($q) => $q->where('user_id', $user->id))->latest()->paginate(20)->withQueryString()
                : null,
        ]);
    }

    public function export(Request $r)
    {
        $d = $r->validate([
            'report' => 'required|in:event,term', 'format' => 'required|in:xlsx,pdf',
            'event_id' => 'required_if:report,event|integer', 'term_id' => 'required_if:report,term|integer',
            'division_id' => 'nullable|integer',
        ]);
        $this->exports->request($r->user(), $d['report'], $d['format'], $d);

        return redirect()->route('reports.index', ['tab' => 'ekspor'])->with('status', 'Ekspor sedang diproses. Muat ulang halaman ini untuk melihat statusnya.');
    }

    public function download(Request $r, Export $export)
    {
        abort_unless($this->exports->canAccess($r->user(), $export), 403);
        abort_unless($export->status === 'ready' && Storage::disk('local')->exists($export->path), 404, 'Ekspor belum siap.');

        return Storage::disk('local')->download($export->path, basename($export->path));
    }
}
