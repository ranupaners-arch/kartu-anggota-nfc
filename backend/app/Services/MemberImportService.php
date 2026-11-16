<?php

namespace App\Services;

use App\Models\Division;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Impor CSV dua langkah: preview (validasi + laporan error) lalu commit.
 * Kolom: member_no, name (wajib); email, phone, cohort, division, status (opsional).
 */
class MemberImportService
{
    public const TTL_MINUTES = 30;
    public const MAX_ROWS = 5000;

    public function preview(User $user, string $path): array
    {
        [$header, $lines] = $this->readCsv($path);
        if (! in_array('member_no', $header) || ! in_array('name', $header)) {
            throw ValidationException::withMessages(['file' => 'Header CSV wajib memuat kolom member_no dan name.']);
        }

        $divisions = Division::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id]);
        $existing = Member::pluck('id', 'member_no');
        $seen = $rows = $errors = [];

        foreach ($lines as $i => $values) {
            $line = $i + 2; // baris 1 = header
            $values = array_pad(array_slice($values, 0, count($header)), count($header), '');
            $row = array_map(fn ($v) => trim((string) $v), array_combine($header, $values));
            $msgs = Validator::make($row, [
                'member_no' => 'required|max:50', 'name' => 'required|max:150', 'email' => 'nullable|email',
                'phone' => 'nullable|max:30', 'cohort' => 'nullable|max:20',
                'status' => 'nullable|in:'.implode(',', MemberService::STATUSES),
            ])->errors()->all();

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
                'email' => ($row['email'] ?? '') ?: null, 'phone' => ($row['phone'] ?? '') ?: null,
                'cohort' => ($row['cohort'] ?? '') ?: null,
                'division_id' => $division !== '' ? $divisions[mb_strtolower($division)] : null,
                'status' => ($row['status'] ?? '') ?: 'active',
            ];
        }

        $token = (string) Str::uuid();
        Cache::put($this->key($user, $token), ['rows' => $rows, 'errors' => count($errors)], now()->addMinutes(self::TTL_MINUTES));

        return [
            'token' => $token, 'expires_in_minutes' => self::TTL_MINUTES,
            'summary' => ['valid' => count($rows), 'create' => collect($rows)->where('action', 'create')->count(),
                'update' => collect($rows)->where('action', 'update')->count(), 'errors' => count($errors)],
            'errors' => $errors, 'sample' => array_slice($rows, 0, 10),
        ];
    }

    /** @return array{created:int,updated:int} */
    public function commit(User $user, string $token, bool $skipErrors = false): array
    {
        $data = Cache::get($this->key($user, $token));
        abort_unless($data, 422, 'Pratinjau kedaluwarsa atau tidak ditemukan. Unggah ulang.');
        abort_if($data['errors'] > 0 && ! $skipErrors, 422,
            "Ada {$data['errors']} baris error. Perbaiki file, atau lewati baris error.");

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
        Cache::forget($this->key($user, $token));
        AuditLogger::log('member.imported', 'term', $term->id, $result + ['skipped_errors' => $data['errors']]);

        return $result;
    }

    /** @return array{0: array<string>, 1: array<array<string>>} */
    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        $first = (string) fgets($fh);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ','; // Excel lokal sering pakai ';'
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $h))), str_getcsv($first, $delimiter, '"', ''));
        $lines = [];
        while (($values = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            if (count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $lines[] = $values;
            abort_if(count($lines) > self::MAX_ROWS, 422, 'Maksimal '.self::MAX_ROWS.' baris per impor.');
        }
        fclose($fh);

        return [$header, $lines];
    }

    private function key(User $user, string $token): string
    {
        return "member-import:{$user->organization_id}:{$user->id}:$token";
    }
}
