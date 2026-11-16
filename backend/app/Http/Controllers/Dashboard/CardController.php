<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Card;
use App\Models\User;
use App\Services\CardService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CardController extends Controller
{
    public function index(Request $r)
    {
        $tab = $r->query('tab', 'semua');
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
