<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\Term;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index(Request $r)
    {
        return view('settings.index', [
            'tab' => $r->query('tab', 'organisasi'),
            'org' => $r->user()->organization,
            'terms' => Term::orderByDesc('starts_on')->get(),
            'divisions' => Division::withCount('memberships')->orderBy('name')->get(),
        ]);
    }

    public function updateOrganization(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:150']);
        $r->user()->organization->update($d);
        AuditLogger::log('organization.updated', 'organization', $r->user()->organization_id);

        return back()->with('status', 'Profil organisasi disimpan.');
    }

    public function storeTerm(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:100', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after:starts_on']);
        $term = Term::create($d + ['is_current' => false]);
        AuditLogger::log('term.created', 'term', $term->id);

        return back()->with('status', 'Periode ditambahkan.');
    }

    /** Jadikan periode berjalan. Data periode lama tidak diubah. */
    public function setCurrentTerm(Term $term)
    {
        DB::transaction(function () use ($term) {
            Term::where('is_current', true)->update(['is_current' => false]);
            $term->update(['is_current' => true]);
        });
        AuditLogger::log('term.set_current', 'term', $term->id);

        return back()->with('status', "Periode berjalan sekarang: {$term->name}.");
    }

    public function storeDivision(Request $r)
    {
        $division = Division::create($this->divisionInput($r));
        AuditLogger::log('division.created', 'division', $division->id);

        return back()->with('status', 'Divisi ditambahkan.');
    }

    public function updateDivision(Request $r, Division $division)
    {
        $division->update($this->divisionInput($r, $division));
        AuditLogger::log('division.updated', 'division', $division->id);

        return back()->with('status', 'Divisi diganti nama.');
    }

    private function divisionInput(Request $r, ?Division $division = null): array
    {
        return $r->validate(['name' => ['required', 'string', 'max:100',
            Rule::unique('divisions')->where('organization_id', $r->user()->organization_id)->ignore($division?->id)]]);
    }
}
