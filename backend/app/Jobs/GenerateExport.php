<?php

namespace App\Jobs;

use App\Exports\ReportSheet;
use App\Models\Event;
use App\Models\Export;
use App\Models\Organization;
use App\Models\Term;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class GenerateExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(public int $exportId) {}

    public function handle(ReportService $reports): void
    {
        // Job berjalan tanpa user login (global scope nonaktif): batasi ke organisasi export secara eksplisit.
        $export = Export::findOrFail($this->exportId);
        $org = Organization::findOrFail($export->organization_id);
        $p = $export->params;

        if ($export->report === 'event') {
            $event = Event::where('organization_id', $org->id)->findOrFail($p['event_id']);
            $data = $reports->event($event);
            $title = "Rekap {$event->title}";
            $headings = ['No. Anggota', 'Nama', 'Divisi', 'Status', 'Waktu Scan', 'Sumber'];
            $rows = collect($data['rows'])->map(fn ($r) => [$r['member_no'], $r['name'], $r['division'], $r['status'], $r['scanned_at'], $r['source']])->all();
        } else {
            $term = Term::where('organization_id', $org->id)->findOrFail($p['term_id']);
            $data = $reports->term($term, $p['division_id'] ?? null);
            $title = "Rekap Periode {$term->name}";
            $headings = ['No. Anggota', 'Nama', 'Divisi', 'Hadir', 'Telat', 'Izin', 'Sakit', 'Alpa', 'Total', '% Hadir'];
            $rows = collect($data['rows'])->map(fn ($r) => [$r['member_no'], $r['name'], $r['division'],
                $r['hadir'], $r['telat'], $r['izin'], $r['sakit'], $r['alpa'], $r['total'], $r['attendance_rate']])->all();
        }

        $path = sprintf('exports/%d/%s-%s.%s', $org->id, Str::slug($title), now()->format('Ymd-His'), $export->format);
        if ($export->format === 'xlsx') {
            Excel::store(new ReportSheet($title, $headings, $rows), $path, 'local');
        } else {
            $pdf = Pdf::loadView('exports.report', compact('org', 'title', 'headings', 'rows'))->setPaper('a4', 'landscape');
            Storage::disk('local')->put($path, $pdf->output());
        }

        $export->update(['status' => 'ready', 'path' => $path, 'error' => null]);
    }

    public function failed(Throwable $e): void
    {
        Export::whereKey($this->exportId)->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);
    }
}
