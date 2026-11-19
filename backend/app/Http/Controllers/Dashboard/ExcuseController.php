<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\Member;
use Illuminate\Validation\ValidationException;
use App\Services\EventService;
use App\Services\ExcuseService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;

class ExcuseController extends Controller
{
    public function __construct(private ExcuseService $excuses, private EventService $events) {}

    public function index(Request $r)
    {
        $tab = $r->query('tab', 'menunggu');
        $f = $r->only('q', 'type');
        $q = $this->excuses->visibleTo($r->user())
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->whereHas('member', fn ($m) => $m->where('name', 'ilike', "%$s%")->orWhere('member_no', 'ilike', "%$s%"))
                ->orWhereHas('event', fn ($e) => $e->where('title', 'ilike', "%$s%"))))
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($tab === 'menunggu', fn ($q) => $q->where('status', 'pending')->oldest(),
                fn ($q) => $q->where('status', '!=', 'pending')->latest('reviewed_at'));

        return Inertia::render('excuses/index', [
            'tab' => $tab,
            'excuses' => $q->paginate(30)->withQueryString(),
            'filters' => $f + ['q' => null, 'type' => null],
            // Untuk formulir "Catat izin": kegiatan yang bisa dikelola, 60 hari terakhir & yang akan datang.
            'events' => fn () => $this->events->visibleTo($r->user())->where('ends_at', '>=', now()->subDays(60))
                ->orderByDesc('starts_at')->limit(100)->get(['id', 'title', 'starts_at', 'closed_at']),
        ]);
    }

    /** Pengurus mencatat izin atas nama anggota (mis. anggota tanpa app), opsional langsung disetujui. */
    public function store(Request $r)
    {
        $d = $r->validate([
            'event_id' => 'required|integer', 'member_no' => 'required|string|max:50', 'type' => 'required|in:izin,sakit',
            'reason' => 'required|string|max:1000', 'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);
        $event = Event::findOrFail($d['event_id']);
        $member = Member::where('member_no', trim($d['member_no']))->first();
        if (! $member) {
            throw ValidationException::withMessages(['member_no' => 'Nomor anggota tidak ditemukan.']);
        }
        $excuse = $this->excuses->submit($r->user(), $event, $member->id, $d['type'], $d['reason'], $r->file('attachment'));
        if ($r->boolean('approve')) {
            $this->excuses->review($r->user(), $excuse, 'approved', 'Dicatat & disetujui pengurus');
        }

        return back()->with('status', "Izin {$member->name} untuk {$event->title} ".($r->boolean('approve') ? 'dicatat & disetujui.' : 'dicatat, menunggu persetujuan.'));
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
