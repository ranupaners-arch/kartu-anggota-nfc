<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Term;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Periode kepengurusan. Hanya satu periode yang berstatus current per organisasi. */
class TermController extends Controller
{
    public function index()
    {
        return Term::orderByDesc('starts_on')->get();
    }

    public function store(Request $r)
    {
        $term = DB::transaction(fn () => $this->save(new Term, $this->validated($r)));
        AuditLogger::log('term.created', 'term', $term->id);

        return response()->json($term, 201);
    }

    public function update(Request $r, Term $term)
    {
        DB::transaction(fn () => $this->save($term, $this->validated($r, true)));
        AuditLogger::log('term.updated', 'term', $term->id);

        return $term->fresh();
    }

    private function save(Term $term, array $d): Term
    {
        if (! empty($d['is_current'])) {
            Term::where('is_current', true)->whereKeyNot($term->id ?? 0)->update(['is_current' => false]);
        }
        $term->fill($d)->save();

        return $term;
    }

    private function validated(Request $r, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $r->validate([
            'name' => "$req|string|max:100", 'starts_on' => "$req|date",
            'ends_on' => "$req|date|after:starts_on", 'is_current' => 'sometimes|boolean',
        ]);
    }
}
