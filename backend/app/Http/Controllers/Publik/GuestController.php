<?php

namespace App\Http\Controllers\Publik;

use App\Http\Controllers\Controller;
use App\Models\EventGuest;
use App\Services\GuestService;
use Inertia\Inertia;

/** QR masuk tamu (tanpa login, token rahasia di URL). */
class GuestController extends Controller
{
    public function show(string $token)
    {
        $g = EventGuest::withoutGlobalScopes()->whereNull('purged_at')->where('token', $token)
            ->with(['event' => fn ($q) => $q->withoutGlobalScopes(), 'event.organization:id,name'])->first();

        return Inertia::render('guests/show', ['guest' => $g ? [
            'name' => $g->name, 'institution' => $g->institution, 'revoked' => $g->revoked_at !== null,
            'event' => $g->event->title, 'location' => $g->event->location, 'starts_at' => $g->event->starts_at, 'closed' => $g->event->closed_at !== null,
            'organization' => $g->event->organization?->name,
            'payload' => $g->revoked_at || $g->event->closed_at ? null : GuestService::payload($g),
        ] : null])->toResponse(request())->setStatusCode($g ? 200 : 404)
            ->withHeaders(['X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'private, no-store']);
    }
}
