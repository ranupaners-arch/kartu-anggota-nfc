<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Export;
use App\Services\ExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Ekspor diproses di queue; klien polling status lalu mengunduh saat ready. */
class ExportController extends Controller
{
    public function __construct(private ExportService $exports) {}

    public function store(Request $r)
    {
        $d = $r->validate([
            'report' => 'required|in:event,term', 'format' => 'required|in:xlsx,pdf',
            'event_id' => 'required_if:report,event|integer', 'term_id' => 'required_if:report,term|integer',
            'division_id' => 'nullable|integer',
        ]);
        $export = $this->exports->request($r->user(), $d['report'], $d['format'], $d);

        return response()->json($this->present($export), 202);
    }

    public function show(Request $r, Export $export)
    {
        abort_unless($this->exports->canAccess($r->user(), $export), 403);

        return $this->present($export);
    }

    public function download(Request $r, Export $export)
    {
        abort_unless($this->exports->canAccess($r->user(), $export), 403);
        abort_unless($export->status === 'ready' && Storage::disk('local')->exists($export->path), 404, 'Ekspor belum siap.');

        return Storage::disk('local')->download($export->path, basename($export->path));
    }

    private function present(Export $e): array
    {
        return $e->only('id', 'report', 'format', 'status', 'error', 'created_at') + [
            'download_url' => $e->status === 'ready' ? url("/api/exports/{$e->id}/download") : null,
        ];
    }
}
