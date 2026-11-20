<?php

namespace App\Http\Controllers\Scanner;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Member;
use App\Services\AuditLogger;
use App\Services\EventService;
use App\Services\ScanService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

/**
 * Mode petugas di browser: pindai QR Saya anggota dengan kamera (untuk petugas pengguna iPhone atau
 * HP tanpa NFC). Hasil dicatat lewat ScanService yang sama dengan app (sumber "qr", tanpa perangkat).
 */
class ScannerController extends Controller
{
    public function __construct(private EventService $events, private ScanService $scans) {}

    /** Kegiatan yang sedang membuka check-in, plus yang dibuka dalam 12 jam ke depan. */
    public function index(Request $r)
    {
        $events = $this->events->visibleTo($r->user())->whereNull('closed_at')
            ->where(fn ($q) => $q->ongoing()->orWhereBetween('checkin_opens_at', [now(), now()->addHours(12)]))
            ->orderBy('checkin_opens_at')->limit(30)->get(['id', 'title', 'location', 'starts_at', 'checkin_opens_at', 'checkin_closes_at']);

        return Inertia::render('scanner/index', [
            'events' => $events->map(fn (Event $e) => $e->only('id', 'title', 'location', 'starts_at', 'checkin_opens_at', 'checkin_closes_at')
                + ['open' => $e->checkin_opens_at->lte(now()) && $e->checkin_closes_at->gte(now())]),
            'isAdmin' => $r->user()->isAdmin(),
        ]);
    }

    public function show(Request $r, Event $event)
    {
        $this->events->authorize($r->user(), $event);
        $session = $event->currentSession() ?? $event->defaultSession();

        return Inertia::render('scanner/scan', [
            'event' => $event->only('id', 'title', 'location', 'closed_at'),
            'session' => $session->only('id', 'title', 'checkin_opens_at', 'late_after', 'checkin_closes_at'),
            'multiSession' => $event->sessions()->count() > 1,
        ]);
    }

    /** Satu hasil pindai (JSON). Foto diarahkan ke rute web yang memeriksa hak akses petugas. */
    public function scan(Request $r, Event $event)
    {
        $this->events->authorize($r->user(), $event);
        $d = $r->validate(['payload' => 'required|string|max:300']);
        $res = $this->scans->process($event, ['payload' => $d['payload'], 'source' => 'qr', 'client_uuid' => (string) Str::uuid()], $r->user(), null);
        if ($res['member']) {
            $res['member']['photo_url'] = $res['member']['photo_url'] ? route('scanner.photo', [$event, $res['member']['id']]) : null;
        }

        return response()->json($res);
    }

    public function password()
    {
        return Inertia::render('scanner/password');
    }

    public function updatePassword(Request $r)
    {
        $r->validate([
            'current_password' => 'required|current_password:web',
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(10)->letters()->numbers()],
        ], ['password.different' => 'Password baru harus berbeda dari password sekarang.']);
        $r->user()->update(['password' => $r->input('password'), 'must_change_password' => false]);
        $r->user()->tokens()->delete();
        AuditLogger::log('user.password_changed', 'user', $r->user()->id);

        return redirect()->route('scanner.index')->with('status', 'Password diganti.');
    }
}
