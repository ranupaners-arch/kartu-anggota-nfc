<?php

namespace App\Services;

use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Buat akun app (peran Anggota) untuk banyak anggota sekaligus. Login memakai email anggota;
 * password sementara wajib diganti saat pertama masuk. Anggota tanpa email, yang sudah punya
 * akun, atau yang emailnya sudah dipakai akun lain dilewati beserta alasannya.
 */
class MemberAccountService
{
    /** @return array{created: list<array{member_no:string,name:string,email:string,password:string}>, skipped: list<array{member_no:string,name:string,reason:string}>} */
    public function createFor(User $actor, array $memberIds): array
    {
        $members = Member::whereIn('id', $memberIds)->whereNull('anonymized_at')->orderBy('name')->get();
        $linked = User::whereIn('member_id', $members->pluck('id'))->pluck('member_id')->flip();
        $taken = User::withoutGlobalScopes()->whereIn(DB::raw('lower(email)'), $members->pluck('email')->filter()->map(fn ($e) => Str::lower($e)))
            ->pluck('email')->map(fn ($e) => Str::lower($e))->flip();

        $created = [];
        $skipped = [];
        DB::transaction(function () use ($actor, $members, $linked, $taken, &$created, &$skipped) {
            foreach ($members as $m) {
                $email = Str::lower(trim((string) $m->email));
                $reason = match (true) {
                    $linked->has($m->id) => 'Sudah punya akun',
                    $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) => 'Belum ada email yang valid',
                    $taken->has($email) => 'Email sudah dipakai akun lain',
                    default => null,
                };
                if ($reason) {
                    $skipped[] = ['member_no' => $m->member_no, 'name' => $m->name, 'reason' => $reason];

                    continue;
                }
                $password = self::readablePassword();
                $user = User::create([
                    'organization_id' => $actor->organization_id, 'name' => $m->name, 'email' => $email,
                    'role' => User::MEMBER, 'member_id' => $m->id, 'password' => $password, 'must_change_password' => true,
                ]);
                $taken->put($email, true);
                AuditLogger::log('user.created', 'user', $user->id, ['role' => User::MEMBER, 'bulk' => true]);
                $created[] = ['member_no' => $m->member_no, 'name' => $m->name, 'email' => $email, 'password' => $password];
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** Huruf/angka yang tidak mirip satu sama lain (tanpa 0 O o 1 l I) — dibagikan di kertas. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    /** Password sementara mudah dibaca, mis. "Kq7m-Xt3a-Pw9e" (12 karakter acak, berisi huruf & angka). */
    public static function readablePassword(): string
    {
        do {
            $raw = '';
            for ($i = 0; $i < 12; $i++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (! preg_match('/\d/', $raw) || ! preg_match('/[A-Za-z]/', $raw));

        return implode('-', str_split($raw, 4));
    }
}
