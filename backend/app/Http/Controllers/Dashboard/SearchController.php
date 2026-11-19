<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Member;
use App\Services\EventService;
use App\Services\MemberService;
use Illuminate\Http\Request;

/** Pencarian cepat (Ctrl+K): anggota & kegiatan yang boleh dilihat pengguna ini. */
class SearchController extends Controller
{
    public const LIMIT = 6;

    public function __invoke(Request $r, MemberService $members, EventService $events)
    {
        $q = trim((string) $r->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['members' => [], 'events' => []]);
        }
        $like = '%'.addcslashes($q, '%_\\').'%';

        return response()->json([
            'members' => $members->visibleTo($r->user())
                ->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('member_no', 'ilike', $like))
                ->orderBy('name')->limit(self::LIMIT)->get(['id', 'member_no', 'name'])
                ->map(fn (Member $m) => ['id' => $m->id, 'member_no' => $m->member_no, 'name' => $m->name]),
            'events' => $events->visibleTo($r->user())
                ->where(fn ($w) => $w->where('title', 'ilike', $like)->orWhere('location', 'ilike', $like))
                ->orderByDesc('starts_at')->limit(self::LIMIT)->get(['id', 'title', 'location', 'starts_at'])
                ->map(fn (Event $e) => ['id' => $e->id, 'title' => $e->title, 'location' => $e->location, 'starts_at' => $e->starts_at->toIso8601String()]),
        ]);
    }
}
