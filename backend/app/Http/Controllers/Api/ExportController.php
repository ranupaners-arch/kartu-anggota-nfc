<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateExport;
use App\Models\Event;
use App\Models\Export;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Ekspor diproses di queue; klien polling status lalu mengunduh saat ready. */
class ExportController extends Controller
{
    public function store(Request $r)
    {
        $d = $r->validate([
            'report' => 'required|in:event,term', 'format' => 'required|in:xlsx,pdf',
            'event_id' => 'required_if:report,event|integer', 'term_id' => 'required_if:report,term|integer',
        ]);

        // Validasi kepemilikan & hak akses sekarang, saat masih ada konteks user.
        $params = [];
        if ($d['report'] === 'event') {
            $event = Event::findOrFail($d['event_id']);
            ReportController::authorizeEvent($r, $event);
            $params['event_id'] = $event->id;
        } else {
            $params['term_id'] = Term::findOrFail($d['term_id'])->id;
            $params['division_id'] = ReportController::divisionScope($r);
        }

        $export = Export::create(['user_id' => $r->user()->id, 'report' => $d['report'], 'format' => $d['format'], 'params' => $params]);
        GenerateExport::dispatch($export->id);

        return response()->json($this->present($export->fresh()), 202);
    }

    public function show(Request $r, Export $export)
    {
        $this->authorizeOwner($r, $export);

        return $this->present($export);
    }

    public function download(Request $r, Export $export)
    {
        $this->authorizeOwner($r, $export);
        abort_unless($export->status === 'ready' && Storage::disk('local')->exists($export->path), 404, 'Ekspor belum siap.');

        return Storage::disk('local')->download($export->path, basename($export->path));
    }

    private function authorizeOwner(Request $r, Export $export): void
    {
        abort_unless($r->user()->isSuperAdmin() || $export->user_id === $r->user()->id, 403);
    }

    private function present(Export $e): array
    {
        return $e->only('id', 'report', 'format', 'status', 'error', 'created_at') + [
            'download_url' => $e->status === 'ready' ? url("/api/exports/{$e->id}/download") : null,
        ];
    }
}
