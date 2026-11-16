<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Division;
use App\Models\Event;
use App\Models\Member;
use App\Services\AttendanceService;
use App\Services\EventService;
use App\Services\ReportService;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(private EventService $events) {}

    public function index(Request $r)
    {
        $tab = $r->query('tab', 'berlangsung');
        $q = $this->events->visibleTo($r->user());
        match ($tab) {
            'akan-datang' => $q->where('checkin_opens_at', '>', now())->orderBy('starts_at'),
            'selesai' => $q->where(fn ($w) => $w->whereNotNull('closed_at')->orWhere('checkin_closes_at', '<', now()))->orderByDesc('starts_at'),
            default => $q->whereNull('closed_at')->where('checkin_opens_at', '<=', now())->where('checkin_closes_at', '>=', now())->orderBy('starts_at'),
        };

        return view('events.index', ['tab' => $tab, 'events' => $q->withCount(['attendances as present_count' => fn ($w) => $w
            ->whereNull('voided_at')->whereIn('status', ['hadir', 'telat'])])->paginate(20)->withQueryString()]);
    }

    public function create()
    {
        return view('events.form', ['event' => new Event(['is_mandatory' => true, 'type' => 'rapat']), 'divisions' => Division::orderBy('name')->get()]);
    }

    public function store(Request $r)
    {
        $d = $r->validate($this->events->rules());
        $d['is_mandatory'] = $r->boolean('is_mandatory');
        $event = $this->events->create($r->user(), $d);

        return redirect()->route('events.show', $event)->with('status', 'Kegiatan dibuat.');
    }

    public function show(Request $r, Event $event, ReportService $reports)
    {
        $this->events->authorize($r->user(), $event);
        $tab = $r->query('tab', 'ringkasan');
        $event->load(['sessions', 'targets']);

        return view('events.show', [
            'event' => $event, 'tab' => $tab,
            'report' => in_array($tab, ['ringkasan', 'hadir'], true) ? $reports->event($event) : null,
            'divisions' => Division::orderBy('name')->get(),
            'targetMembers' => Member::whereIn('id', $event->targets->pluck('member_id')->filter())->get(),
        ]);
    }

    public function edit(Request $r, Event $event)
    {
        $this->events->authorize($r->user(), $event);

        return view('events.form', ['event' => $event, 'divisions' => Division::orderBy('name')->get()]);
    }

    public function update(Request $r, Event $event)
    {
        $this->events->authorize($r->user(), $event);
        $d = $r->validate($this->events->rules(partial: true));
        $d['is_mandatory'] = $r->boolean('is_mandatory');
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
        $d = $r->validate(['title' => 'required|string|max:200', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at']);
        $event->defaultSession(); // pastikan sesi utama ada lebih dulu
        $event->sessions()->create($d);

        return back()->with('status', 'Sesi ditambahkan.');
    }

    public function correct(Request $r, Attendance $attendance, AttendanceService $service)
    {
        $d = $r->validate(['status' => 'required|in:void,'.implode(',', AttendanceService::STATUSES), 'reason' => 'required|string|min:3|max:500']);
        $service->correct($r->user(), $attendance, $d['status'] === 'void' ? null : $d['status'], $d['status'] === 'void', $d['reason']);

        return back()->with('status', 'Absensi dikoreksi.');
    }
}
