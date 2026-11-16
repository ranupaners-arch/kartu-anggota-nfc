<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Term;
use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function event(Request $r, Event $event)
    {
        $this->authorizeEvent($r, $event);

        return $this->reports->event($event);
    }

    /** Rekap periode; ?division_id= untuk rekap per divisi. Admin Divisi dipaksa ke divisinya. */
    public function term(Request $r, Term $term)
    {
        return $this->reports->term($term, $this->divisionScope($r));
    }

    public static function authorizeEvent(Request $r, Event $event): void
    {
        $u = $r->user();
        abort_if($u->role === 'division_admin' && $event->division_id && $event->division_id !== $u->division_id,
            403, 'Bukan kegiatan divisi Anda.');
    }

    public static function divisionScope(Request $r): ?int
    {
        $u = $r->user();
        if ($u->role === 'division_admin') {
            return $u->division_id ?? abort(403, 'Akun belum terhubung ke divisi.');
        }

        return $r->integer('division_id') ?: null;
    }
}
