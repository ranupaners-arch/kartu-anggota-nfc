<?php

namespace App\Http\Controllers\Publik;

use App\Http\Controllers\Controller;
use App\Services\CertificateService;
use Inertia\Inertia;

/** Cek keaslian sertifikat dari QR (tanpa login). */
class CertificateController extends Controller
{
    public function verify(string $code, CertificateService $certificates)
    {
        $result = $certificates->verify($code);

        return Inertia::render('certificates/verify', ['result' => $result])->toResponse(request())
            ->setStatusCode($result ? 200 : 404)->header('X-Robots-Tag', 'noindex');
    }
}
