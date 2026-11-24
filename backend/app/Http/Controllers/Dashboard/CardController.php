<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Card;
use App\Models\CardRequest;
use App\Models\User;
use App\Services\CardService;
use App\Services\SelfCardService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CardController extends Controller
{
    /** Setujui (jatah tulis kartu sendiri +1) atau tolak permintaan kartu pengganti. */
    public function reviewRequest(Request $r, CardRequest $request, SelfCardService $cards)
    {
        $d = $r->validate(['action' => 'required|in:approve,reject', 'resolution' => 'nullable|in:self,admin', 'note' => 'nullable|string|max:500']);
        $resolution = $d['resolution'] ?? 'self';
        $cards->review($r->user(), $request, $d['action'] === 'approve', $d['note'] ?? null, $resolution);

        return back()->with('status', match (true) {
            $d['action'] === 'reject' => 'Permintaan ditolak.',
            $resolution === 'admin' => 'Disetujui: terbitkan kartunya lewat app petugas (Terbitkan Kartu), lalu serahkan ke anggota.',
            default => 'Disetujui. Anggota bisa mendaftarkan kartu baru di app-nya.',
        });
    }

    public function index(Request $r)
    {
        $tab = $r->query('tab', 'semua');
        if ($tab === 'pengganti') {
            return Inertia::render('cards/index', ['tab' => $tab, 'filters' => ['q' => null, 'status' => null],
                'items' => CardRequest::with('member:id,member_no,name,self_card_writes_left', 'reviewer:id,name')
                    ->orderByRaw("status = 'pending' desc")->latest()->paginate(30)->withQueryString()]);
        }
        if ($tab === 'ditolak') {
            $items = AuditLog::where('action', 'scan.blocked')
                ->whereIn('user_id', User::where('organization_id', $r->user()->organization_id)->select('id'))
                ->latest('created_at')->paginate(30)->withQueryString();
            $cards = Card::with('member:id,member_no,name')->whereIn('id', $items->pluck('entity_id'))->get()->keyBy('id');

            return Inertia::render('cards/index', ['tab' => $tab, 'filters' => ['q' => null, 'status' => null],
                'items' => $items->through(fn (AuditLog $l) => [
                    'id' => $l->id, 'created_at' => $l->created_at, 'card_status' => $l->payload['status'] ?? null,
                    'event_id' => $l->payload['event_id'] ?? null, 'device_id' => $l->payload['device_id'] ?? null,
                    'member' => $cards[$l->entity_id]?->member?->only('member_no', 'name'),
                ])]);
        }

        $items = Card::with('member:id,member_no,name')
            ->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($r->query('q'), fn ($q, $s) => $q->whereHas('member', fn ($m) => $m
                ->where('name', 'ilike', "%$s%")->orWhere('member_no', 'ilike', "%$s%")))
            ->latest('issued_at')->paginate(30)->withQueryString();

        return Inertia::render('cards/index', ['tab' => $tab, 'items' => $items, 'filters' => ['q' => $r->query('q'), 'status' => $r->query('status')]]);
    }

    public function block(Request $r, Card $card, CardService $cards)
    {
        $d = $r->validate(['status' => 'required|in:blocked,lost']);
        $cards->block($card, $d['status']);

        return back()->with('status', $d['status'] === 'lost' ? 'Kartu ditandai hilang.' : 'Kartu diblokir.');
    }
}
