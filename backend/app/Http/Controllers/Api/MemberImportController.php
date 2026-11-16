<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Term;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Impor CSV dua langkah: preview (validasi + laporan error) lalu commit.
 * Kolom: member_no, name (wajib); email, phone, cohort, division, status (opsional).
 */
class MemberImportController extends Controller
{
    private const TTL_MINUTES = 30;

    public function preview(Request $r)
    {
        $r->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);
        [$header, $lines] = $this->readCsv($r->file('file')->getRealPath());
        abort_unless(in_array('member_no', $header) && in_array('name', $header), 422,
            'Header CSV wajib memuat kolom member_no dan name.');

        $divisions = Division::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id]);
        $existing = Member::pluck('id', 'member_no');
        $seen = [];
        $rows = [];
        $errors = [];

        foreach ($lines as $i => $values) {
            $line = $i + 2; // baris 1 = header
            $row = array_map(fn ($v) => trim((string) $v), array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), '')));
            $v = Validator::make($row, [
                'member_no' => 'required|max:50', 'name' => 'required|max:150', 'email' => 'nullable|email',
                'phone' => 'nullable|max:30', 'cohort' => 'nullable|max:20',
                'status' => 'nullable|in:applicant,active,inactive,alumni',
            ]);
            $msgs = $v->errors()->all();
            $division = $row['division'] ?? '';
            if ($division !== '' && ! $divisions->has(mb_strtolower($division))) {
                $msgs[] = "Divisi \"$division\" tidak ditemukan.";
            }
            if (isset($seen[$row['member_no']])) {
                $msgs[] = "member_no duplikat dengan baris {$seen[$row['member_no']]}.";
            }
            $seen[$row['member_no']] ??= $line;

            if ($msgs) {
                $errors[] = ['line' => $line, 'member_no' => $row['member_no'], 'errors' => $msgs];

                continue;
            }
            $rows[] = [
                'action' => $existing->has($row['member_no']) ? 'update' : 'create',
                'member_no' => $row['member_no'], 'name' => $row['name'],
                'email' => $row['email'] ?? null ?: null, 'phone' => $row['phone'] ?? null ?: null,
                'cohort' => $row['cohort'] ?? null ?: null,
                'division_id' => $division !== '' ? $divisions[mb_strtolower($division)] : null,
                'status' => ($row['status'] ?? '') ?: 'active',
            ];
        }

        $token = (string) Str::uuid();
        Cache::put($this->key($r, $token), ['rows' => $rows, 'errors' => count($errors)], now()->addMinutes(self::TTL_MINUTES));

        return [
            'token' => $token, 'expires_in_minutes' => self::TTL_MINUTES,
            'summary' => ['valid' => count($rows), 'create' => collect($rows)->where('action', 'create')->count(),
                'update' => collect($rows)->where('action', 'update')->count(), 'errors' => count($errors)],
            'errors' => $errors, 'sample' => array_slice($rows, 0, 10),
        ];
    }

    public function commit(Request $r)
    {
        $d = $r->validate(['token' => 'required|uuid', 'skip_errors' => 'sometimes|boolean']);
        $data = Cache::get($this->key($r, $d['token']));
        abort_unless($data, 422, 'Pratinjau kedaluwarsa atau tidak ditemukan. Unggah ulang.');
        abort_if($data['errors'] > 0 && empty($d['skip_errors']), 422,
            "Ada {$data['errors']} baris error. Perbaiki file, atau kirim skip_errors=true untuk melewatinya.");

        $term = Term::where('is_current', true)->firstOr(fn () => abort(422, 'Belum ada periode aktif.'));
        $result = DB::transaction(function () use ($data, $term) {
            $n = ['created' => 0, 'updated' => 0];
            foreach ($data['rows'] as $row) {
                $member = Member::withTrashed()->firstOrNew(['member_no' => $row['member_no']]);
                $n[$member->exists ? 'updated' : 'created']++;
                $member->fill(array_intersect_key($row, array_flip(['name', 'email', 'phone', 'cohort'])));
                $member->deleted_at = null;
                $member->consented_at ??= now();
                $member->save();
                Membership::updateOrCreate(['member_id' => $member->id, 'term_id' => $term->id],
                    ['division_id' => $row['division_id'], 'status' => $row['status']]);
            }

            return $n;
        });
        Cache::forget($this->key($r, $d['token']));
        AuditLogger::log('member.imported', 'term', $term->id, $result + ['skipped_errors' => $data['errors']]);

        return $result;
    }

    /** @return array{0: array<string>, 1: array<array<string>>} */
    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        $first = fgets($fh);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ','; // Excel lokal sering pakai ';'
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $h))), str_getcsv($first, $delimiter, '"', ''));
        $lines = [];
        while (($values = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            if (count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $lines[] = $values;
            abort_if(count($lines) > 5000, 422, 'Maksimal 5000 baris per impor.');
        }
        fclose($fh);

        return [$header, $lines];
    }

    private function key(Request $r, string $token): string
    {
        return "member-import:{$r->user()->organization_id}:{$r->user()->id}:$token";
    }
}
