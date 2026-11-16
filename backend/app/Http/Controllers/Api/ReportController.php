<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Term;
use App\Services\EventService;
use App\Services\ExportService;
use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports, private EventService $events, private ExportService $exports) {}

    public function event(Request $r, Event $event)
    {
        $this->events->authorize($r->user(), $event);

        return $this->reports->event($event);
    }

    /** Rekap periode; ?division_id= untuk rekap per divisi. Admin Divisi dipaksa ke divisinya. */
    public function term(Request $r, Term $term)
    {
        return $this->reports->term($term, $this->exports->divisionScope($r->user(), $r->integer('division_id') ?: null));
    }
}
