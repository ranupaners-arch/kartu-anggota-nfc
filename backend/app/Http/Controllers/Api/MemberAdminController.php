<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Division;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Term;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Kelola anggota (Super Admin). Keanggotaan disimpan per periode berjalan. */
class MemberAdminController extends Controller
{
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
        $d = $this->validated($r);
        $member = DB::transaction(function () use ($d) {
            $member = Member::create(collect($d)->only('member_no', 'name', 'email', 'phone', 'cohort')->all() + ['consented_at' => now()]);
            $this->syncMembership($member, $d);

            return $member;
        });
        AuditLogger::log('member.created', 'member', $member->id);

        return response()->json($this->show($member), 201);
    }

    public function update(Request $r, Member $member)
    {
        $d = $this->validated($r, $member);
        DB::transaction(function () use ($member, $d) {
            $member->update(collect($d)->only('member_no', 'name', 'email', 'phone', 'cohort')->all());
            $this->syncMembership($member, $d);
        });
        AuditLogger::log('member.updated', 'member', $member->id, ['fields' => array_keys($d)]);

        return $this->show($member->fresh());
    }

    /** Soft delete. Kartu aktif ikut diblokir agar tidak bisa dipakai absen. */
    public function destroy(Member $member)
    {
        DB::transaction(function () use ($member) {
            Card::where('member_id', $member->id)->where('status', Card::ACTIVE)
                ->update(['status' => Card::BLOCKED, 'revoked_at' => now()]);
            $member->delete();
        });
        AuditLogger::log('member.deleted', 'member', $member->id);

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

    private function syncMembership(Member $member, array $d): void
    {
        if (! array_key_exists('division_id', $d) && ! array_key_exists('status', $d)) {
            return;
        }
        $term = $this->currentTerm();
        $ms = Membership::firstOrNew(['member_id' => $member->id, 'term_id' => $term->id]);
        $ms->fill(array_intersect_key($d, array_flip(['division_id', 'status', 'role'])))->save();
    }

    private function validated(Request $r, ?Member $member = null): array
    {
        $req = $member ? 'sometimes' : 'required';
        $orgId = $r->user()->organization_id;
        $d = $r->validate([
            'member_no' => [$req, 'string', 'max:50', Rule::unique('members')->where('organization_id', $orgId)
                ->whereNull('deleted_at')->ignore($member?->id)],
            'name' => "$req|string|max:150",
            'email' => 'nullable|email|max:150', 'phone' => 'nullable|string|max:30', 'cohort' => 'nullable|string|max:20',
            'division_id' => 'nullable|integer', 'status' => 'sometimes|in:applicant,active,inactive,alumni',
            'role' => 'nullable|string|max:50',
        ]);
        if (! empty($d['division_id'])) {
            Division::findOrFail($d['division_id']); // ter-scope ke organisasi
        }
        if (! $member && ! isset($d['status'])) {
            $d['status'] = 'active';
        }

        return $d;
    }

    private function currentTerm(): Term
    {
        return Term::where('is_current', true)->firstOr(fn () => abort(422, 'Belum ada periode aktif.'));
    }
}
