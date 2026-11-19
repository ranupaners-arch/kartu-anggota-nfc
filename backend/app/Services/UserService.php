<?php

namespace App\Services;

use App\Models\Division;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Kelola akun pengguna & peran dalam satu organisasi (Super Admin). */
class UserService
{
    public const ROLES = [User::SUPER_ADMIN, User::DIVISION_ADMIN, User::SCANNER, User::MEMBER];

    public function rules(?User $user = null): array
    {
        $req = $user ? 'sometimes' : 'required';

        return [
            'name' => "$req|string|max:150",
            'email' => [$req, 'email', 'max:150', Rule::unique('users')->ignore($user?->id)],
            'role' => "$req|in:".implode(',', self::ROLES),
            'division_id' => 'nullable|integer',
            'member_id' => 'nullable|integer',
        ];
    }

    /** @return array{user: User, password: string} password sementara, ditampilkan sekali */
    public function create(User $actor, array $d): array
    {
        $this->assertRefs($d);
        $password = Str::password(12, symbols: false);
        $user = User::create(array_intersect_key($d, array_flip(['name', 'email', 'role', 'division_id', 'member_id'])) + [
            'organization_id' => $actor->organization_id, 'password' => $password, 'must_change_password' => true,
        ]);
        AuditLogger::log('user.created', 'user', $user->id, ['role' => $user->role]);

        return ['user' => $user, 'password' => $password];
    }

    public function update(User $actor, User $user, array $d): User
    {
        $this->assertSameOrg($actor, $user);
        $this->assertRefs($d);
        if ($user->is($actor) && isset($d['role']) && $d['role'] !== $user->role) {
            throw ValidationException::withMessages(['role' => 'Tidak bisa mengubah peran akun sendiri.']);
        }
        $user->update(array_intersect_key($d, array_flip(['name', 'email', 'role', 'division_id', 'member_id'])));
        AuditLogger::log('user.updated', 'user', $user->id, ['fields' => array_keys($d)]);

        return $user->fresh();
    }

    public function resetPassword(User $actor, User $user): string
    {
        $this->assertSameOrg($actor, $user);
        $password = Str::password(12, symbols: false);
        $user->update(['password' => $password, 'must_change_password' => true]);
        $user->tokens()->delete(); // keluarkan dari semua perangkat
        AuditLogger::log('user.password_reset', 'user', $user->id);

        return $password;
    }

    public function setDisabled(User $actor, User $user, bool $disabled): User
    {
        $this->assertSameOrg($actor, $user);
        abort_if($user->is($actor), 422, 'Tidak bisa menonaktifkan akun sendiri.');
        $user->update(['disabled_at' => $disabled ? now() : null]);
        if ($disabled) {
            $user->tokens()->delete();
        }
        AuditLogger::log($disabled ? 'user.disabled' : 'user.enabled', 'user', $user->id);

        return $user->fresh();
    }

    private function assertSameOrg(User $actor, User $user): void
    {
        abort_unless($user->organization_id === $actor->organization_id, 404);
    }

    private function assertRefs(array $d): void
    {
        if (! empty($d['division_id'])) {
            Division::findOrFail($d['division_id']);
        }
        if (! empty($d['member_id'])) {
            Member::findOrFail($d['member_id']);
        }
        if (($d['role'] ?? null) === User::DIVISION_ADMIN && empty($d['division_id'])) {
            throw ValidationException::withMessages(['division_id' => 'Admin Divisi wajib punya divisi.']);
        }
    }
}
