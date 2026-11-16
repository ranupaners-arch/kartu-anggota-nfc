<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ExcuseRequest;
use App\Services\EventService;
use App\Services\ExcuseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExcuseController extends Controller
{
    public function __construct(private ExcuseService $excuses) {}

    public function index(Request $r)
    {
        $tab = $r->query('tab', 'menunggu');
        $q = $this->excuses->visibleTo($r->user())
            ->when($tab === 'menunggu', fn ($q) => $q->where('status', 'pending')->oldest(),
                fn ($q) => $q->where('status', '!=', 'pending')->latest('reviewed_at'));

        return view('excuses.index', ['tab' => $tab, 'excuses' => $q->paginate(30)->withQueryString()]);
    }

    public function review(Request $r, ExcuseRequest $excuse)
    {
        $d = $r->validate(['status' => 'required|in:approved,rejected', 'note' => 'nullable|string|max:500']);
        $this->excuses->review($r->user(), $excuse, $d['status'], $d['note'] ?? null);

        return back()->with('status', $d['status'] === 'approved' ? 'Izin disetujui.' : 'Izin ditolak.');
    }

    /** Bukti izin disimpan privat; hanya pengurus yang berhak bisa membuka. */
    public function attachment(Request $r, ExcuseRequest $excuse, EventService $events)
    {
        $events->authorize($r->user(), $excuse->event);
        abort_unless($excuse->attachment_path && Storage::disk('local')->exists($excuse->attachment_path), 404);

        return Storage::disk('local')->response($excuse->attachment_path);
    }
}
