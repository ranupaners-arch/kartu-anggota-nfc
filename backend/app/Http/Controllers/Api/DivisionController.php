<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DivisionController extends Controller
{
    public function index()
    {
        return Division::orderBy('name')->get();
    }

    public function store(Request $r)
    {
        $division = Division::create($this->validated($r));
        AuditLogger::log('division.created', 'division', $division->id);

        return response()->json($division, 201);
    }

    public function update(Request $r, Division $division)
    {
        $division->update($this->validated($r, $division));
        AuditLogger::log('division.updated', 'division', $division->id);

        return $division;
    }

    private function validated(Request $r, ?Division $division = null): array
    {
        return $r->validate(['name' => ['required', 'string', 'max:100',
            Rule::unique('divisions')->where('organization_id', $r->user()->organization_id)->ignore($division?->id)]]);
    }
}
