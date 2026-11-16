<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MemberImportService;
use Illuminate\Http\Request;

/** Impor CSV anggota dua langkah (logika di MemberImportService). */
class MemberImportController extends Controller
{
    public function __construct(private MemberImportService $import) {}

    public function preview(Request $r)
    {
        $r->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);

        return $this->import->preview($r->user(), $r->file('file')->getRealPath());
    }

    public function commit(Request $r)
    {
        $d = $r->validate(['token' => 'required|uuid', 'skip_errors' => 'sometimes|boolean']);

        return $this->import->commit($r->user(), $d['token'], (bool) ($d['skip_errors'] ?? false));
    }
}
