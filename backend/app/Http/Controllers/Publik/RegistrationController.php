<?php

namespace App\Http\Controllers\Publik;

use App\Http\Controllers\Controller;
use App\Services\RegistrationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Formulir pendaftaran anggota mandiri (tautan publik dari pengurus, tanpa login). */
class RegistrationController extends Controller
{
    public function __construct(private RegistrationService $registrations) {}

    public function show(string $token)
    {
        $org = $this->registrations->organizationFor($token);
        $term = $org ? $this->registrations->currentTerm($org) : null;
        if (! $org || ! $term) {
            return Inertia::render('register/closed')->toResponse(request())->setStatusCode(404);
        }

        return Inertia::render('register/form', [
            'token' => $token, 'organization' => $org->name, 'term' => $term->name,
            'divisions' => $this->registrations->divisions($org),
        ]);
    }

    public function store(Request $r, string $token)
    {
        $org = $this->registrations->organizationFor($token);
        $term = $org ? $this->registrations->currentTerm($org) : null;
        abort_unless($org && $term, 404);
        // Jebakan bot: kolom tersembunyi harus kosong (manusia tidak melihatnya).
        if (filled($r->input('website'))) {
            return redirect()->route('register.done', $token);
        }
        $d = $r->validate($this->registrations->rules($org), $this->registrations->messages());
        $this->registrations->register($org, $term, $d, $r->file('photo'));

        return redirect()->route('register.done', $token);
    }

    public function done(string $token)
    {
        $org = $this->registrations->organizationFor($token);
        abort_unless($org, 404);

        return Inertia::render('register/done', ['organization' => $org->name]);
    }
}
