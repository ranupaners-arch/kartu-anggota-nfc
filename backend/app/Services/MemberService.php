<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Division;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Term;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Kelola anggota dan keanggotaannya di periode berjalan. Dipakai API dan dashboard. */
class MemberService
{
    public const STATUSES = ['applicant', 'active', 'inactive', 'alumni'];

    /** Aturan validasi; $member null = buat baru. */
    public function rules(int $organizationId, ?Member $member = null): array
    {
        $req = $member ? 'sometimes' : 'required';

        return [
            'member_no' => [$req, 'string', 'max:50', Rule::unique('members')->where('organization_id', $organizationId)
                ->whereNull('deleted_at')->ignore($member?->id)],
            'name' => "$req|string|max:150",
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:30',
            'cohort' => 'nullable|string|max:20',
            'division_id' => 'nullable|integer',
            'status' => 'sometimes|in:'.implode(',', self::STATUSES),
            'role' => 'nullable|string|max:50',
            // Anggota baru wajib sudah menyetujui datanya diproses (PRD §13 Privasi).
            'consent' => $member ? 'sometimes|accepted' : 'accepted',
        ];
    }

    public function currentTerm(): Term
    {
        return Term::where('is_current', true)->firstOr(fn () => abort(422, 'Belum ada periode aktif.'));
    }

    public function create(array $d): Member
    {
        $this->assertDivision($d);
        $d['status'] ??= 'active';
        $member = DB::transaction(function () use ($d) {
            $member = Member::create($this->memberFields($d) + ['consented_at' => now(), 'consented_by' => auth()->id()]);
            $this->syncMembership($member, $d);

            return $member;
        });
        AuditLogger::log('member.created', 'member', $member->id);

        return $member;
    }

    public function update(Member $member, array $d): Member
    {
        $this->assertNotAnonymized($member);
        $this->assertDivision($d);
        DB::transaction(function () use ($member, $d) {
            $member->update($this->memberFields($d));
            if (! empty($d['consent']) && ! $member->consented_at) {
                $member->forceFill(['consented_at' => now(), 'consented_by' => auth()->id()])->save();
            }
            $this->syncMembership($member, $d);
        });
        AuditLogger::log('member.updated', 'member', $member->id, ['fields' => array_keys($d)]);

        return $member->fresh();
    }

    /** Soft delete. Kartu aktif ikut diblokir agar tidak bisa dipakai absen. */
    public function delete(Member $member): void
    {
        DB::transaction(function () use ($member) {
            Card::where('member_id', $member->id)->where('status', Card::ACTIVE)
                ->update(['status' => Card::BLOCKED, 'revoked_at' => now()]);
            $member->delete();
        });
        AuditLogger::log('member.deleted', 'member', $member->id);
    }

    /** Ganti foto anggota (diperkecil, disk privat); foto lama dihapus. */
    public function setPhoto(Member $member, \Illuminate\Http\UploadedFile $file): Member
    {
        $this->assertNotAnonymized($member);
        $old = $member->photo_path;
        $member->update(['photo_path' => app(PhotoService::class)->store($file, "members/{$member->organization_id}")]);
        if ($old) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($old);
        }
        AuditLogger::log('member.photo_updated', 'member', $member->id);

        return $member;
    }

    /** Catat persetujuan anggota lama yang belum tercatat. */
    public function recordConsent(Member $member): Member
    {
        $this->assertNotAnonymized($member);
        $member->forceFill(['consented_at' => now(), 'consented_by' => auth()->id()])->save();
        AuditLogger::log('member.consent_recorded', 'member', $member->id);

        return $member;
    }

    public function assertNotAnonymized(Member $member): void
    {
        abort_if($member->anonymized_at, 422, 'Data pribadi anggota ini sudah dihapus dan tidak bisa diubah.');
    }

    private function memberFields(array $d): array
    {
        return array_intersect_key($d, array_flip(['member_no', 'name', 'email', 'phone', 'cohort']));
    }

    private function syncMembership(Member $member, array $d): void
    {
        if (! array_intersect_key($d, array_flip(['division_id', 'status', 'role']))) {
            return;
        }
        $ms = Membership::firstOrNew(['member_id' => $member->id, 'term_id' => $this->currentTerm()->id]);
        $ms->fill(array_intersect_key($d, array_flip(['division_id', 'status', 'role'])));
        $ms->status ??= 'active';
        $ms->save();
    }

    private function assertDivision(array $d): void
    {
        if (! empty($d['division_id'])) {
            Division::findOrFail($d['division_id']); // ter-scope ke organisasi
        }
    }
}
