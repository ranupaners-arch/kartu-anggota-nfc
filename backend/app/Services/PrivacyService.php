<?php

namespace App\Services;

use App\Models\Card;
use App\Models\ExcuseRequest;
use App\Models\Export;
use App\Models\Member;
use App\Models\MemberQr;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Privasi data anggota (PRD §13): hapus data pribadi ("hak hapus") dan retensi.
 *
 * Hapus data pribadi = anonimisasi permanen: identitas, kontak, foto, alasan & lampiran izin, dan
 * akun login dihapus; catatan kehadiran (status per kegiatan) tetap ada agar rekap tidak berubah.
 */
class PrivacyService
{
    public const ANON_NAME = 'Anggota terhapus';
    public const PURGED_REASON = '[dihapus]';

    /** Alasan anggota belum boleh dihapus datanya, atau null bila boleh. */
    public function blocker(Member $member): ?string
    {
        if ($member->anonymized_at) {
            return 'Data pribadi anggota ini sudah dihapus.';
        }
        $activeNow = $member->memberships()->where('status', 'active')
            ->whereHas('term', fn ($t) => $t->where('is_current', true))->exists();

        return $activeNow ? 'Anggota masih aktif di periode berjalan. Ubah statusnya dulu (mis. alumni atau tidak aktif).' : null;
    }

    public function anonymize(Member $member, ?User $actor, string $reason): void
    {
        abort_if($blocker = $this->blocker($member), 422, $blocker);

        $files = [];
        DB::transaction(function () use ($member, &$files) {
            $files[] = $member->photo_path;
            Card::where('member_id', $member->id)->where('status', Card::ACTIVE)->update(['status' => Card::BLOCKED, 'revoked_at' => now()]);
            MemberQr::where('member_id', $member->id)->delete();

            $excuses = ExcuseRequest::withoutGlobalScopes()->where('member_id', $member->id)->get();
            foreach ($excuses as $x) {
                $files[] = $x->attachment_path;
            }
            ExcuseRequest::withoutGlobalScopes()->where('member_id', $member->id)
                ->update(['reason' => self::PURGED_REASON, 'review_note' => null, 'attachment_path' => null, 'purged_at' => now()]);

            // Akun login anggota: dinonaktifkan & identitasnya dihapus (baris tetap ada untuk referensi log).
            User::where('member_id', $member->id)->get()->each(function (User $u) {
                $u->tokens()->delete();
                $u->forceFill([
                    'name' => 'Akun terhapus', 'email' => "hapus-{$u->id}@akun.invalid", 'password' => Hash::make(Str::random(40)), // tidak bisa login lagi
                    'member_id' => null, 'disabled_at' => now(), 'remember_token' => null,
                    'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null,
                ])->save();
            });

            $member->forceFill([
                'name' => self::ANON_NAME, 'member_no' => "HAPUS-{$member->id}", 'email' => null, 'phone' => null, 'cohort' => null,
                'photo_path' => null, 'consented_at' => null, 'consented_by' => null, 'anonymized_at' => now(),
            ])->save();
        });
        $this->deleteFiles($files);

        // Log tanpa data pribadi: hanya id & alasan.
        AuditLogger::log('member.anonymized', 'member', $member->id, ['reason' => $reason, 'automatic' => $actor === null], $actor?->id);
    }

    /**
     * Jalankan retensi untuk satu organisasi. $dryRun: hanya hitung.
     *
     * @return array{excuses: int, members: int, exports: int}
     */
    public function retention(Organization $org, bool $dryRun = false): array
    {
        $excuses = ExcuseRequest::withoutGlobalScopes()->whereNull('purged_at')
            ->whereHas('event', fn ($e) => $e->withoutGlobalScopes()->where('organization_id', $org->id)
                ->whereHas('term', fn ($t) => $t->withoutGlobalScopes()->where('ends_on', '<', now()->subDays($org->retention_excuse_days)->toDateString())));

        $members = $org->retention_member_months === null ? null : Member::withoutGlobalScopes()->where('organization_id', $org->id)
            ->whereNull('anonymized_at')
            ->whereDoesntHave('memberships', fn ($m) => $m->whereHas('term', fn ($t) => $t->withoutGlobalScopes()
                ->where(fn ($w) => $w->where('is_current', true)->orWhere('ends_on', '>=', now()->subMonths($org->retention_member_months)->toDateString()))));

        $exports = Export::withoutGlobalScopes()->where('organization_id', $org->id)->whereNotNull('path')
            ->where('created_at', '<', now()->subDays((int) config('attendance.export_retention_days')));

        if ($dryRun) {
            return ['excuses' => $excuses->count(), 'members' => $members?->count() ?? 0, 'exports' => $exports->count()];
        }

        $n = ['excuses' => 0, 'members' => 0, 'exports' => 0];
        $excuses->get()->each(function (ExcuseRequest $x) use (&$n) {
            $file = $x->attachment_path;
            $x->update(['reason' => self::PURGED_REASON, 'review_note' => null, 'attachment_path' => null, 'purged_at' => now()]);
            $this->deleteFiles([$file]);
            $n['excuses']++;
        });
        $members?->get()->each(function (Member $m) use (&$n, $org) {
            $this->anonymize($m, null, "Retensi otomatis: {$org->retention_member_months} bulan setelah periode terakhir");
            $n['members']++;
        });
        $exports->get()->each(function (Export $e) use (&$n) {
            $this->deleteFiles([$e->path]);
            $e->update(['path' => null, 'status' => 'expired']);
            $n['exports']++;
        });
        if (array_sum($n) > 0) {
            AuditLogger::log('privacy.retention', 'organization', $org->id, $n);
        }

        return $n;
    }

    private function deleteFiles(array $paths): void
    {
        foreach (array_filter($paths) as $p) {
            Storage::disk('local')->delete($p);
        }
    }
}
