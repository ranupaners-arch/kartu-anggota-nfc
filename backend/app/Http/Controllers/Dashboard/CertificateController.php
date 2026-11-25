<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Event;
use App\Services\CertificateService;
use App\Services\EventService;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    public function __construct(private CertificateService $certificates, private EventService $events) {}

    public function issue(Request $r, Event $event)
    {
        $n = $this->certificates->issue($r->user(), $event, $r->validate($this->certificates->rules()));

        return back()->with('status', "Sertifikat: {$n['added']} diterbitkan, {$n['revoked']} dicabut, {$n['restored']} dipulihkan.");
    }

    public function revoke(Request $r, Certificate $certificate)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:300']);
        $this->certificates->revoke($r->user(), $certificate, $d['reason']);

        return back()->with('status', 'Sertifikat dicabut.');
    }

    public function restore(Request $r, Certificate $certificate)
    {
        $this->certificates->restore($r->user(), $certificate);

        return back()->with('status', 'Sertifikat berlaku lagi.');
    }

    public function pdf(Request $r, Certificate $certificate)
    {
        $this->events->authorize($r->user(), $certificate->event);
        [$pdf, $name] = $this->certificates->pdf($certificate);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'"']);
    }
}
