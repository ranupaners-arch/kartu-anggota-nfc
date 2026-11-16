<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Term;
use App\Services\MemberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Kelola anggota (Super Admin). Keanggotaan disimpan per periode berjalan. */
class MemberAdminController extends Controller
{
    public function __construct(private MemberService $members) {}

    public function index(Request $r)
    {
        $term = $this->currentTerm();

        return Member::query()
            ->with(['memberships' => fn ($q) => $q->where('term_id', $term->id)->with('division:id,name'), 'activeCard:id,member_id,public_id,status'])
            ->when($r->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%$s%")->orWhere('member_no', 'ilike', "%$s%")))
            ->when($r->query('division_id'), fn ($q, $d) => $q->whereHas('memberships', fn ($m) => $m
                ->where('term_id', $term->id)->where('division_id', $d)))
            ->when($r->query('status'), fn ($q, $s) => $q->whereHas('memberships', fn ($m) => $m
                ->where('term_id', $term->id)->where('status', $s)))
            ->orderBy('name')->paginate(min((int) $r->query('per_page', 30), 100));
    }

    public function show(Member $member)
    {
        return $member->load(['memberships.term:id,name', 'memberships.division:id,name', 'cards']);
    }

    public function store(Request $r)
    {
        $member = $this->members->create($r->validate($this->members->rules($r->user()->organization_id)));

        return response()->json($this->show($member), 201);
    }

    public function update(Request $r, Member $member)
    {
        $member = $this->members->update($member, $r->validate($this->members->rules($r->user()->organization_id, $member)));

        return $this->show($member);
    }

    public function destroy(Member $member)
    {
        $this->members->delete($member);

        return response()->noContent();
    }

    public function uploadPhoto(Request $r, Member $member)
    {
        $r->validate(['photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048']);
        if ($member->photo_path) {
            Storage::disk('local')->delete($member->photo_path);
        }
        // Disk privat: foto hanya bisa diambil lewat endpoint ber-auth.
        $member->update(['photo_path' => $r->file('photo')->store("members/{$member->organization_id}", 'local')]);

        return $this->show($member);
    }

    public function photo(Request $r, Member $member)
    {
        $u = $r->user();
        abort_unless($u->role !== 'member' || $u->member_id === $member->id, 403);
        abort_unless($member->photo_path && Storage::disk('local')->exists($member->photo_path), 404);

        return Storage::disk('local')->response($member->photo_path, headers: ['Cache-Control' => 'private, max-age=3600']);
    }

    private function currentTerm(): Term
    {
        return $this->members->currentTerm();
    }
}
