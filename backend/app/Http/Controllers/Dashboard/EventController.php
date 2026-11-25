<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\Division;
use App\Models\Event;
use App\Models\EventDocument;
use App\Models\EventMinutes;
use App\Models\EventSession;
use App\Models\Member;
use App\Services\AttendanceService;
use App\Services\AuditLogger;
use App\Services\CertificateService;
use App\Services\EventService;
use App\Services\MinutesService;
use App\Services\ReportService;
use App\Services\RsvpService;
use App\Services\SurveyService;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EventController extends Controller
{
    public function __construct(private EventService $events) {}

    public function index(Request $r)
    {
        $tab = $r->query('tab', 'berlangsung');
        $f = $r->only('q', 'division', 'type', 'from', 'to');
        $q = $this->events->visibleTo($r->user())
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('title', 'ilike', "%$s%")->orWhere('location', 'ilike', "%$s%")))
            ->when($f['division'] ?? null, fn ($q, $d) => $d === 'umum' ? $q->whereNull('division_id') : $q->where('division_id', (int) $d))
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('starts_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('starts_at', '<', Carbon::parse($d)->addDay()->startOfDay()));
        if ($tab === 'kalender') {
            return $this->calendar($r, $q, $f);
        }
        match ($tab) {
            'akan-datang' => $q->where('checkin_opens_at', '>', now())->orderBy('starts_at'),
            'selesai' => $q->finished()->orderByDesc('starts_at'),
            default => $q->ongoing()->orderBy('starts_at'),
        };

        return Inertia::render('events/index', [
            'tab' => $tab,
            'events' => $q->select('events.*')->withPresentCount()->paginate(20)->withQueryString(),
            'filters' => $f + ['q' => null, 'division' => null, 'type' => null, 'from' => null, 'to' => null],
            'divisions' => $r->user()->isSuperAdmin() ? Division::orderBy('name')->get(['id', 'name']) : [],
            'types' => $this->events->visibleTo($r->user())->whereNotNull('type')->distinct()->orderBy('type')->pluck('type'),
        ]);
    }

    /**
     * Kalender bulanan (?bulan=YYYY-MM, bawaan bulan ini). Kotak kalender Senin–Minggu mencakup
     * hari dari bulan sebelum/sesudahnya, jadi kegiatan diambil untuk seluruh rentang kotak.
     */
    private function calendar(Request $r, $q, array $f)
    {
        $month = rescue(fn () => Carbon::createFromFormat('!Y-m', (string) $r->query('bulan')), null, false) ?: now()->startOfMonth();
        $from = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $to = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $events = $q->where('starts_at', '<=', $to)->where('ends_at', '>=', $from)->orderBy('starts_at')
            ->get(['id', 'title', 'type', 'location', 'division_id', 'is_mandatory', 'starts_at', 'ends_at', 'closed_at']);

        return Inertia::render('events/index', [
            'tab' => 'kalender',
            'calendar' => [
                'month' => $month->format('Y-m'),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'events' => $events,
            ],
            'filters' => $f + ['q' => null, 'division' => null, 'type' => null, 'from' => null, 'to' => null],
            // Semua divisi (urut id → warna tetap per divisi walau difilter); admin divisi juga perlu namanya.
            'allDivisions' => Division::orderBy('id')->get(['id', 'name']),
            'divisions' => $r->user()->isSuperAdmin() ? Division::orderBy('name')->get(['id', 'name']) : [],
            'types' => $this->events->visibleTo($r->user())->whereNotNull('type')->distinct()->orderBy('type')->pluck('type'),
        ]);
    }

    public function create()
    {
        return Inertia::render('events/form', ['event' => (object) [], 'divisions' => Division::orderBy('name')->get(['id', 'name']), 'windowLocked' => false]);
    }

    public function store(Request $r)
    {
        $d = $r->validate($this->events->rules());
        $d['is_mandatory'] = $r->boolean('is_mandatory');
        $d['rsvp_enabled'] = $r->boolean('rsvp_enabled');
        $event = $this->events->create($r->user(), $d);

        return redirect()->route('events.show', $event)->with('status', 'Kegiatan dibuat.');
    }

    public function show(Request $r, Event $event, ReportService $reports, RsvpService $rsvps, CertificateService $certificates, MinutesService $minutes, SurveyService $surveys)
    {
        $this->events->authorize($r->user(), $event);
        $tab = $r->query('tab', 'ringkasan');
        $event->load(['sessions', 'targets']);
        // ?sesi= memilih sesi untuk ringkasan & daftar hadir (bawaan: sesi utama).
        $session = $r->integer('sesi') ? $event->sessions()->findOrFail($r->integer('sesi')) : null;

        return Inertia::render('events/show', [
            'event' => $event->only('id', 'title', 'location', 'starts_at', 'ends_at', 'checkin_opens_at', 'late_after', 'checkin_closes_at', 'closed_at', 'rsvp_enabled', 'rsvp_deadline', 'quota'),
            'certificate' => $tab === 'sertifikat' ? $this->certificateTab($r, $event, $certificates) : null,
            'minutes' => $tab === 'notulen' ? $minutes->tab($event) : null,
            'survey' => $tab === 'survei' ? $surveys->tab($event) : null,
            'rsvp' => $tab === 'rsvp' && $event->rsvp_enabled ? $rsvps->report($event) : null,
            'tab' => $tab,
            'report' => in_array($tab, ['ringkasan', 'hadir'], true) ? $reports->event($event, $session) : null,
            'canDelete' => ! $event->attendances()->exists() && ! EventMinutes::where('event_id', $event->id)->exists() && ! EventDocument::where('event_id', $event->id)->exists(),
            'sessions' => $reports->sessions($event),
            'divisions' => Division::orderBy('name')->get(['id', 'name']),
            'targetDivisionIds' => $event->targets->pluck('division_id')->filter()->values(),
            'targetMemberNos' => Member::whereIn('id', $event->targets->pluck('member_id')->filter())->pluck('member_no'),
        ]);
    }

    private function certificateTab(Request $r, Event $event, CertificateService $certificates): array
    {
        $batch = CertificateBatch::where('event_id', $event->id)->first();
        // Kegiatan pertama: isi penanda tangan dari sertifikat terakhir organisasi.
        $last = $batch ?? CertificateBatch::latest('updated_at')->first();
        $plan = $event->closed_at ? $certificates->plan($event, $batch->min_sessions ?? 1) : null;

        return [
            'issued' => (bool) $batch,
            'form' => ['title' => $batch->title ?? 'Sertifikat', 'role' => $batch->role ?? 'Peserta', 'min_sessions' => $batch->min_sessions ?? 1]
                + collect(['signer1_name', 'signer1_title', 'signer2_name', 'signer2_title'])->mapWithKeys(fn ($k) => [$k => $last?->{$k} ?? ''])->all(),
            'plan' => $plan ? ['eligible' => $plan['eligible'], 'add' => count($plan['add']), 'revoke' => count($plan['revoke']), 'restore' => count($plan['restore'])] : null,
            'items' => Certificate::with('member:id,member_no')->where('event_id', $event->id)->orderBy('seq')->paginate(50)->withQueryString()
                ->through(fn (Certificate $c) => ['id' => $c->id, 'number' => $certificates->number($c, $event), 'name' => $c->member_name, 'member_no' => $c->member?->member_no,
                    'revoked_at' => $c->revoked_at, 'reason' => $c->revoke_reason, 'auto' => $c->auto_revoked]),
        ];
    }

    public function edit(Request $r, Event $event)
    {
        $this->events->authorize($r->user(), $event);

        return Inertia::render('events/form', [
            'event' => $event->only('id', 'title', 'type', 'location', 'is_mandatory', 'starts_at', 'ends_at', 'checkin_opens_at', 'late_after', 'checkin_closes_at', 'rsvp_enabled', 'rsvp_deadline', 'quota'),
            'divisions' => Division::orderBy('name')->get(['id', 'name']),
            'windowLocked' => $event->checkin_opens_at->lte(now()),
        ]);
    }

    public function update(Request $r, Event $event)
    {
        $this->events->authorize($r->user(), $event);
        $d = $r->validate($this->events->rules(partial: true));
        $d['is_mandatory'] = $r->boolean('is_mandatory');
        $d['rsvp_enabled'] = $r->boolean('rsvp_enabled');
        $this->events->update($event, $d, $r->input('reason'));

        return redirect()->route('events.show', $event)->with('status', 'Kegiatan diperbarui.');
    }

    public function destroy(Request $r, Event $event)
    {
        $this->events->authorize($r->user(), $event);
        $this->events->delete($event);

        return redirect()->route('events.index')->with('status', 'Kegiatan dihapus.');
    }

    public function close(Request $r, Event $event)
    {
        $this->events->authorize($r->user(), $event);
        $n = $this->events->close($event);

        return back()->with('status', "Kegiatan ditutup. $n anggota ditandai Alpa/Izin/Sakit otomatis.");
    }

    public function targets(Request $r, Event $event)
    {
        $this->events->authorize($r->user(), $event);
        $d = $r->validate(['division_ids' => 'array', 'division_ids.*' => 'integer', 'member_nos' => 'nullable|string|max:5000']);
        $nos = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($d['member_nos'] ?? '')))));
        $members = Member::whereIn('member_no', $nos)->pluck('id', 'member_no');
        if ($missing = array_diff($nos, $members->keys()->all())) {
            return back()->withErrors(['member_nos' => 'Nomor anggota tidak ditemukan: '.implode(', ', $missing)])->withInput();
        }
        $this->events->setTargets($event, $d['division_ids'] ?? [], $members->values()->all());

        return back()->with('status', 'Target peserta diperbarui.');
    }

    public function storeSession(Request $r, Event $event)
    {
        $this->events->authorize($r->user(), $event);
        abort_if($event->closed_at, 422, 'Kegiatan sudah ditutup.');
        // Jam mulai wajib: jendela check-in sesi dihitung darinya (selisih sama dengan kegiatan).
        $d = $r->validate(['title' => 'required|string|max:200', 'starts_at' => 'required|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at']);
        $event->defaultSession(); // pastikan sesi utama ada lebih dulu
        $session = $event->sessions()->create(LocalTime::normalize($d, ['starts_at', 'ends_at']));
        AuditLogger::log('event.session_added', 'event', $event->id, ['session_id' => $session->id, 'title' => $session->title]);

        return back()->with('status', 'Sesi ditambahkan. Check-in dibuka '.$session->checkin_opens_at->timezone(config('app.timezone'))->format('d/m H:i').'.');
    }

    /** Duplikat kegiatan ke tanggal baru, opsional berulang (mingguan / dua mingguan / bulanan). */
    public function duplicate(Request $r, Event $event)
    {
        $d = $r->validate([
            'starts_at' => 'required|date',
            'repeat' => 'required|in:'.implode(',', EventService::REPEATS),
            'count' => 'required_unless:repeat,none|integer|min:1|max:'.EventService::MAX_REPEAT,
        ]);
        $created = $this->events->duplicate($r->user(), $event, LocalTime::parse($d['starts_at']), $d['repeat'], (int) ($d['count'] ?? 1));
        $first = $created->first();

        return redirect()->route('events.show', $first)->with('status', $created->count() > 1
            ? "{$created->count()} kegiatan dibuat (".$first->starts_at->timezone(config('app.timezone'))->format('d/m').' – '.$created->last()->starts_at->timezone(config('app.timezone'))->format('d/m').').'
            : 'Kegiatan diduplikat. Periksa jadwal & target sebelum check-in dibuka.');
    }

    public function updateSession(Request $r, Event $event, EventSession $session)
    {
        $this->events->authorize($r->user(), $event);
        $d = $r->validate(['title' => 'required|string|max:200', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at', 'reason' => 'nullable|string|max:300']);
        $isDefault = $event->defaultSession()->is($session);
        if (! $isDefault && empty($d['starts_at'])) {
            throw ValidationException::withMessages(['starts_at' => 'Jam mulai sesi wajib diisi.']);
        }
        $this->events->updateSession($event, $session, collect($d)->except('reason')->all(), $d['reason'] ?? null);

        return back()->with('status', 'Sesi diperbarui.');
    }

    public function destroySession(Request $r, Event $event, EventSession $session)
    {
        $this->events->authorize($r->user(), $event);
        $this->events->deleteSession($event, $session);

        return back()->with('status', 'Sesi dihapus.');
    }

    public function correct(Request $r, Attendance $attendance, AttendanceService $service)
    {
        $d = $r->validate(['status' => 'required|in:void,'.implode(',', AttendanceService::STATUSES), 'reason' => 'required|string|min:3|max:500']);
        $service->correct($r->user(), $attendance, $d['status'] === 'void' ? null : $d['status'], $d['status'] === 'void', $d['reason']);

        return back()->with('status', 'Absensi dikoreksi.');
    }
}
