<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Division;
use App\Models\Member;
use App\Models\Membership;
use App\Models\User;
use App\Services\MemberImportService;
use App\Services\MemberService;
use App\Services\PrivacyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;

class MemberController extends Controller
{
    public function __construct(private MemberService $members) {}

    public function index(Request $r)
    {
        $term = $this->members->currentTerm();
        $superAdmin = $r->user()->isSuperAdmin();
        $tab = $r->query('tab', 'aktif');
        if ($tab === 'arsip' && $superAdmin) {
            return $this->archive($r, $term);
        }
        $q = $this->members->visibleTo($r->user())
            ->with(['memberships' => fn ($m) => $m->where('term_id', $term->id)->with('division:id,name'), 'activeCard:id,member_id,status'])
            ->when($tab !== 'semua', fn ($q) => $q->whereHas('memberships', fn ($m) => $m->where('term_id', $term->id)
                ->where('status', $tab === 'alumni' ? 'alumni' : 'active')))
            ->when($r->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'ilike', "%$s%")->orWhere('member_no', 'ilike', "%$s%")))
            ->when($r->query('division_id'), fn ($q, $d) => $q->whereHas('memberships', fn ($m) => $m->where('term_id', $term->id)->where('division_id', $d)))
            ->orderBy('name');

        return Inertia::render('members/index', [
            'tab' => $tab, 'term' => $term->only('id', 'name'),
            'members' => $q->paginate(30)->withQueryString()->through(fn (Member $m) => [
                'id' => $m->id, 'member_no' => $m->member_no, 'name' => $m->name, 'cohort' => $m->cohort,
                'division' => $m->memberships->first()?->division?->name, 'status' => $m->memberships->first()?->status,
                'has_card' => (bool) $m->activeCard,
            ]),
            'divisions' => $superAdmin ? Division::orderBy('name')->get(['id', 'name']) : [],
            'filters' => ['q' => $r->query('q'), 'division_id' => $r->query('division_id')],
            'canManage' => $superAdmin,
        ]);
    }

    /** Anggota yang dihapus (soft delete), bisa dipulihkan. Yang data pribadinya dihapus tidak tampil. */
    private function archive(Request $r, \App\Models\Term $term)
    {
        $q = Member::onlyTrashed()->whereNull('anonymized_at')
            ->when($r->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'ilike', "%$s%")->orWhere('member_no', 'ilike', "%$s%")))
            ->latest('deleted_at');

        return Inertia::render('members/index', [
            'tab' => 'arsip', 'term' => $term->only('id', 'name'),
            'members' => $q->paginate(30)->withQueryString()->through(fn (Member $m) => [
                'id' => $m->id, 'member_no' => $m->member_no, 'name' => $m->name, 'cohort' => $m->cohort,
                'division' => null, 'status' => null, 'has_card' => false, 'deleted_at' => $m->deleted_at,
            ]),
            'divisions' => [], 'filters' => ['q' => $r->query('q'), 'division_id' => null], 'canManage' => true,
        ]);
    }

    public function restore(Member $member)
    {
        $this->members->restore($member);

        return redirect()->route('members.show', $member)->with('status', 'Anggota dipulihkan. Kartu lamanya tetap diblokir; terbitkan kartu baru bila perlu.');
    }

    public function create()
    {
        return Inertia::render('members/form', ['member' => (object) [], 'membership' => ['division_id' => null, 'status' => 'active', 'role' => null], 'divisions' => Division::orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $r)
    {
        $member = $this->members->create($this->input($r));

        return redirect()->route('members.show', $member)->with('status', 'Anggota ditambahkan.');
    }

    public function show(Request $r, Member $member)
    {
        $this->members->authorizeView($r->user(), $member);
        $tab = $r->query('tab', 'profil');
        $member->load(['memberships.term:id,name', 'memberships.division:id,name', 'cards']);

        return Inertia::render('members/show', [
            'member' => $member->only('id', 'member_no', 'name', 'email', 'phone', 'cohort')
                + ['photo_url' => $member->photoUrl('members.photo')],
            'canManage' => $r->user()->isSuperAdmin(),
            'privacy' => [
                'consentedAt' => $member->consented_at,
                'consentedBy' => $member->consented_by ? User::find($member->consented_by)?->name : null,
                'anonymizedAt' => $member->anonymized_at,
                'blocker' => app(PrivacyService::class)->blocker($member),
            ],
            'tab' => $tab,
            'memberships' => $member->memberships->sortByDesc('term_id')->values()->map(fn ($ms) => [
                'id' => $ms->id, 'term_id' => $ms->term_id, 'term' => $ms->term?->name, 'division' => $ms->division?->name, 'role' => $ms->role, 'status' => $ms->status,
            ]),
            'cards' => $member->cards->sortByDesc('issued_at')->values()->map->only('id', 'public_id', 'status', 'issued_at', 'revoked_at'),
            'attendances' => $tab === 'riwayat'
                ? Attendance::with('event:id,title,starts_at')->where('member_id', $member->id)->latest('scanned_at')->paginate(30)->withQueryString()
                : null,
        ]);
    }

    public function edit(Member $member)
    {
        $membership = Membership::firstOrNew(['member_id' => $member->id, 'term_id' => $this->members->currentTerm()->id]);

        return Inertia::render('members/form', [
            'member' => $member->only('id', 'member_no', 'name', 'email', 'phone', 'cohort'),
            'membership' => ['division_id' => $membership->division_id, 'status' => $membership->status ?? 'active', 'role' => $membership->role],
            'divisions' => Division::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $r, Member $member)
    {
        $this->members->update($member, $this->input($r, $member));

        return redirect()->route('members.show', $member)->with('status', 'Data anggota diperbarui.');
    }

    public function consent(Member $member)
    {
        $this->members->recordConsent($member);

        return back()->with('status', 'Persetujuan anggota dicatat.');
    }

    /** Hapus data pribadi (permanen). Nomor anggota diketik ulang sebagai konfirmasi. */
    public function anonymize(Request $r, Member $member, PrivacyService $privacy)
    {
        $r->validate([
            'confirm' => ['required', 'string', fn ($a, $v, $fail) => $v === $member->member_no ? null : $fail('Ketik nomor anggota persis untuk konfirmasi.')],
            'reason' => 'required|string|min:5|max:300',
        ]);
        $privacy->anonymize($member, $r->user(), $r->input('reason'));

        return redirect()->route('members.show', $member)->with('status', 'Data pribadi anggota dihapus. Catatan kehadirannya tetap ada di rekap.');
    }

    public function destroy(Member $member)
    {
        $this->members->delete($member);

        return redirect()->route('members.index')->with('status', 'Anggota dihapus dan kartunya diblokir.');
    }

    public function uploadPhoto(Request $r, Member $member)
    {
        $r->validate(['photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120']);
        $this->members->setPhoto($member, $r->file('photo'));

        return back()->with('status', 'Foto diperbarui.');
    }

    /** Rapor kehadiran PDF; ?periode= (bawaan: periode berjalan). */
    public function reportCard(Request $r, Member $member, \App\Services\ReportCardService $cards)
    {
        $this->members->authorizeView($r->user(), $member);
        $term = $r->integer('periode') ? \App\Models\Term::findOrFail($r->integer('periode')) : $this->members->currentTerm();
        [$pdf, $name] = $cards->pdf($member, $term);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'"']);
    }

    public function photo(Request $r, Member $member)
    {
        $this->members->authorizeView($r->user(), $member);
        abort_unless($member->photo_path && Storage::disk('local')->exists($member->photo_path), 404);

        return Storage::disk('local')->response($member->photo_path, headers: ['Cache-Control' => 'private, max-age=3600']);
    }

    public function importForm()
    {
        return Inertia::render('members/import', ['preview' => null]);
    }

    public function importPreview(Request $r, MemberImportService $import)
    {
        $r->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);

        return Inertia::render('members/import', ['preview' => $import->preview($r->user(), $r->file('file')->getRealPath())]);
    }

    public function importCommit(Request $r, MemberImportService $import)
    {
        $d = $r->validate(['token' => 'required|uuid', 'consent' => 'accepted'],
            ['consent.accepted' => 'Centang pernyataan bahwa anggota di berkas sudah menyetujui datanya diproses.']);
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
