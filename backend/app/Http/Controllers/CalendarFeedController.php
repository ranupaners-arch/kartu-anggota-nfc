<?php

namespace App\Http\Controllers;

use App\Services\CalendarFeedService;
use Illuminate\Http\Request;

class CalendarFeedController extends Controller
{
    public function __construct(private CalendarFeedService $feeds) {}

    /** Tautan langganan publik; token = kunci. Token salah/akun nonaktif → 404. */
    public function show(string $token)
    {
        $user = $this->feeds->userForToken($token) ?? abort(404);

        return response($this->feeds->ics($user, $this->feeds->eventsFor($user)), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="jadwal.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }

    /** Dashboard (halaman Akun) & API (app anggota): ambil atau ganti tautan. */
    public function url(Request $r)
    {
        return ['url' => $this->feeds->url($r->user())];
    }

    public function reset(Request $r)
    {
        $hadLink = (bool) $r->user()->calendar_token;
        $url = $this->feeds->url($r->user(), reset: true);

        return $r->expectsJson() ? ['url' => $url] : back()->with('status', $hadLink
            ? 'Tautan langganan diganti. Tautan lama tidak berlaku lagi.'
            : 'Tautan langganan dibuat. Salin lalu tambahkan ke aplikasi kalender Anda.');
    }
}
