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
            ->with(['memberships' => fn ($m) => $m->where('term_id', $term->id)->with('division:id,name'), 'activeCard:id,member_id,status'])->withExists('user')
            ->when($tab !== 'semua', fn ($q) => $q->whereHas('memberships', fn ($m) => $m->where('term_id', $term->id)
                ->where('status', match ($tab) { 'alumni' => 'alumni', 'pendaftar' => 'applicant', default => 'active' })))
            ->when($r->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'ilike', "%$s%")->orWhere('member_no', 'ilike', "%$s%")))
            ->when($r->query('division_id'), fn ($q, $d) => $q->whereHas('memberships', fn ($m) => $m->where('term_id', $term->id)->where('division_id', $d)))
            // Kesiapan: anggota yang belum punya akun app / kartu aktif (untuk memantau persiapan).
            ->when($r->query('siap') === 'tanpa-akun', fn ($q) => $q->whereDoesntHave('user'))
            ->when($r->query('siap') === 'tanpa-kartu', fn ($q) => $q->whereDoesntHave('activeCard'))
            ->orderBy('name');

        return Inertia::render('members/index', [
            'tab' => $tab, 'term' => $term->only('id', 'name'),
            'members' => $q->paginate(30)->withQueryString()->through(fn (Member $m) => [
                'id' => $m->id, 'member_no' => $m->member_no, 'name' => $m->name, 'cohort' => $m->cohort,
                'division' => $m->memberships->first()?->division?->name, 'status' => $m->memberships->first()?->status,
                'has_card' => (bool) $m->activeCard,
                'has_account' => $m->user_exists ?? false,
            ]),
            'divisions' => $superAdmin ? Division::orderBy('name')->get(['id', 'name']) : [],
            'filters' => ['q' => $r->query('q'), 'division_id' => $r->query('division_id'), 'siap' => $r->query('siap')],
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
            'duty' => $r->user()->isSuperAdmin() ? DutyController::forMember($member) : null,
            'cardPreview' => app(\App\Services\MemberCardService::class)->forMember($member, $r->user()->organization?->name),
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
            'cards' => $this->cardHistory($member),
            // Laporan kartu (hilang/rusak/HP tanpa NFC) & jatah daftar kartu sendiri.
            'cardRequests' => \App\Models\CardRequest::with('reviewer:id,name')->where('member_id', $member->id)->latest()->get()
                ->map(fn ($q) => $q->only('id', 'reason', 'note', 'status', 'resolution', 'review_note', 'created_at', 'reviewed_at') + ['reviewer' => $q->reviewer?->name]),
            'selfCardWritesLeft' => (int) $member->self_card_writes_left,
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

    public function bulk(Request $r)
    {
        $d = $r->validate([
            'ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer',
            'action' => 'required|in:'.implode(',', MemberService::BULK_ACTIONS),
            'value' => 'nullable|string|max:20',
        ]);
        $n = $this->members->bulk($d['ids'], $d['action'], $d['value'] ?? null);
        $what = ['division' => 'dipindahkan divisinya', 'status' => 'diubah statusnya', 'archive' => 'diarsipkan, kartunya diblokir'][$d['action']];

        return back()->with('status', "$n anggota $what.");
    }

    /** Kartu anggota + siapa yang menerbitkan (pengurus lewat app petugas, atau anggota sendiri). */
    private function cardHistory(Member $member): \Illuminate\Support\Collection
    {
        $cards = $member->cards->sortByDesc('issued_at')->values();
        $logs = \App\Models\AuditLog::where('entity', 'card')->whereIn('entity_id', $cards->pluck('id'))
            ->whereIn('action', ['card.issued', 'card.self_issued'])->get()->keyBy('entity_id');
        $names = User::whereIn('id', $logs->pluck('user_id')->filter())->pluck('name', 'id');

        return $cards->map(fn ($c) => $c->only('id', 'public_id', 'status', 'issued_at', 'revoked_at') + [
            'issued_by' => match ($logs[$c->id]->action ?? null) {
                'card.self_issued' => 'Anggota sendiri',
                'card.issued' => $names[$logs[$c->id]->user_id] ?? 'Pengurus',
                default => null,
            },
        ]);
    }

    /** Beri 1 jatah daftar kartu NFC sendiri (maks. 3), mis. setelah dicek langsung oleh pengurus. */
    public function grantSelfCard(Member $member)
    {
        abort_if($member->self_card_writes_left >= 3, 422, 'Jatah sudah maksimal (3).');
        $member->increment('self_card_writes_left');
        \App\Services\AuditLogger::log('card.self_write_granted', 'member', $member->id);

        return back()->with('status', 'Jatah daftar kartu sendiri ditambah 1.');
    }

    /** Desain kartu siap cetak (PDF) untuk anggota terpilih; ?format=a4 (bawaan) atau kartu (printer PVC). */
    public function printCards(Request $r, \App\Services\CardPrintService $printer)
    {
        $d = $r->validate([
            'ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer',
            'format' => 'nullable|in:'.implode(',', \App\Services\CardPrintService::FORMATS),
        ]);
        $term = $this->members->currentTerm();
        $members = Member::whereIn('id', $d['ids'])->whereNull('anonymized_at')->orderBy('name')
            ->with(['memberships' => fn ($q) => $q->where('term_id', $term->id)->with('division:id,name'), 'activeCard:id,member_id,public_id'])->get();
        abort_if($members->isEmpty(), 404, 'Anggota tidak ditemukan.');
        [$pdf, $name] = $printer->pdf($members, $term, $r->user()->organization, $d['format'] ?? 'a4');
        \App\Services\AuditLogger::log('member.cards_printed', 'member', null, ['count' => $members->count()]);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'"']);
    }

    /** Buat akun app untuk anggota terpilih; hasil (password sementara) hanya ditampilkan sekali. */
    public function createAccounts(Request $r, \App\Services\MemberAccountService $accounts)
    {
        $d = $r->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer']);
        $res = $accounts->createFor($r->user(), $d['ids']);

        return redirect()->route('members.accounts.slips')->with('accountSlips', $res);
    }

    /** Lembar akun untuk dicetak/dibagikan. Data hanya ada di sesi sekali (flash); dimuat ulang → hilang. */
    public function accountSlips(Request $r)
    {
        $res = $r->session()->get('accountSlips');
        if (! $res) {
            return redirect()->route('members.index')->with('status', 'Lembar akun hanya ditampilkan sekali. Reset password dari Pengguna & Peran bila hilang.');
        }

        return Inertia::render('members/account-slips', [
            'created' => $res['created'], 'skipped' => $res['skipped'],
            'organization' => $r->user()->organization?->name,
            // QR penghubung (sama dengan Pengaturan → Aplikasi): anggota pindai, app langsung terhubung.
            'connect' => SettingsController::connectInfo(),
        ]);
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
