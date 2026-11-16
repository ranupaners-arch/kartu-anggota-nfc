<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Division;
use App\Models\Member;
use App\Models\Membership;
use App\Services\MemberImportService;
use App\Services\MemberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MemberController extends Controller
{
    public function __construct(private MemberService $members) {}

    public function index(Request $r)
    {
        $term = $this->members->currentTerm();
        $tab = $r->query('tab', 'aktif');
        $q = Member::query()
            ->with(['memberships' => fn ($m) => $m->where('term_id', $term->id)->with('division:id,name'), 'activeCard:id,member_id,status'])
            ->when($tab !== 'semua', fn ($q) => $q->whereHas('memberships', fn ($m) => $m->where('term_id', $term->id)
                ->where('status', $tab === 'alumni' ? 'alumni' : 'active')))
            ->when($r->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'ilike', "%$s%")->orWhere('member_no', 'ilike', "%$s%")))
            ->when($r->query('division_id'), fn ($q, $d) => $q->whereHas('memberships', fn ($m) => $m->where('term_id', $term->id)->where('division_id', $d)))
            ->orderBy('name');

        return view('members.index', ['tab' => $tab, 'members' => $q->paginate(30)->withQueryString(), 'divisions' => Division::orderBy('name')->get(), 'term' => $term]);
    }

    public function create()
    {
        return view('members.form', ['member' => new Member, 'membership' => new Membership(['status' => 'active']), 'divisions' => Division::orderBy('name')->get()]);
    }

    public function store(Request $r)
    {
        $member = $this->members->create($this->input($r));

        return redirect()->route('members.show', $member)->with('status', 'Anggota ditambahkan.');
    }

    public function show(Request $r, Member $member)
    {
        $tab = $r->query('tab', 'profil');
        $member->load(['memberships.term:id,name', 'memberships.division:id,name', 'cards']);

        return view('members.show', [
            'member' => $member, 'tab' => $tab,
            'attendances' => $tab === 'riwayat'
                ? Attendance::with('event:id,title,starts_at')->where('member_id', $member->id)->latest('scanned_at')->paginate(30)->withQueryString()
                : null,
        ]);
    }

    public function edit(Member $member)
    {
        $membership = Membership::firstOrNew(['member_id' => $member->id, 'term_id' => $this->members->currentTerm()->id]);

        return view('members.form', ['member' => $member, 'membership' => $membership, 'divisions' => Division::orderBy('name')->get()]);
    }

    public function update(Request $r, Member $member)
    {
        $this->members->update($member, $this->input($r, $member));

        return redirect()->route('members.show', $member)->with('status', 'Data anggota diperbarui.');
    }

    public function destroy(Member $member)
    {
        $this->members->delete($member);

        return redirect()->route('members.index')->with('status', 'Anggota dihapus dan kartunya diblokir.');
    }

    public function uploadPhoto(Request $r, Member $member)
    {
        $r->validate(['photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048']);
        if ($member->photo_path) {
            Storage::disk('local')->delete($member->photo_path);
        }
        $member->update(['photo_path' => $r->file('photo')->store("members/{$member->organization_id}", 'local')]);

        return back()->with('status', 'Foto diperbarui.');
    }

    public function photo(Member $member)
    {
        abort_unless($member->photo_path && Storage::disk('local')->exists($member->photo_path), 404);

        return Storage::disk('local')->response($member->photo_path, headers: ['Cache-Control' => 'private, max-age=3600']);
    }

    public function importForm()
    {
        return view('members.import');
    }

    public function importPreview(Request $r, MemberImportService $import)
    {
        $r->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);

        return view('members.import', ['preview' => $import->preview($r->user(), $r->file('file')->getRealPath())]);
    }

    public function importCommit(Request $r, MemberImportService $import)
    {
        $d = $r->validate(['token' => 'required|uuid']);
        $n = $import->commit($r->user(), $d['token'], $r->boolean('skip_errors'));

        return redirect()->route('members.index')->with('status', "Impor selesai: {$n['created']} ditambahkan, {$n['updated']} diperbarui.");
    }

    private function input(Request $r, ?Member $member = null): array
    {
        $d = $r->validate($this->members->rules($r->user()->organization_id, $member));
        $d['division_id'] = $d['division_id'] ?? null;

        return $d;
    }
}
