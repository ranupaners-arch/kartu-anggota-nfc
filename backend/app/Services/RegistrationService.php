<?php

namespace App\Services;

use App\Models\Division;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Pendaftaran anggota mandiri lewat tautan publik (tanpa login). Pendaftar masuk dengan status
 * keanggotaan "Pendaftar" di periode berjalan dan baru bisa absen setelah pengurus mengubahnya ke Aktif.
 * Diakses tanpa sesi login, jadi semua kueri dibatasi ke organisasi pemilik tautan secara eksplisit.
 */
class RegistrationService
{
    public function __construct(private PhotoService $photos) {}

    public function organizationFor(string $token): ?Organization
    {
        return Organization::where('registration_token', $token)->first();
    }

    public function open(Organization $org, bool $rotate = false): Organization
    {
        if ($rotate || ! $org->registration_token) {
            $org->forceFill(['registration_token' => Str::random(24)])->save();
        }

        return $org;
    }

    public function close(Organization $org): Organization
    {
        $org->forceFill(['registration_token' => null])->save();

        return $org;
    }

    public function currentTerm(Organization $org): ?Term
    {
        return Term::withoutGlobalScopes()->where('organization_id', $org->id)->where('is_current', true)->first();
    }

    public function divisions(Organization $org)
    {
        return Division::withoutGlobalScopes()->where('organization_id', $org->id)->orderBy('name')->get(['id', 'name']);
    }

    public function rules(Organization $org): array
    {
        return [
            'name' => 'required|string|max:150',
            'member_no' => ['required', 'string', 'max:50', Rule::unique('members')->where('organization_id', $org->id)],
            'email' => ['required', 'email', 'max:150', Rule::unique('members')->where('organization_id', $org->id)],
            'phone' => 'nullable|string|max:30',
            'cohort' => 'nullable|string|max:20',
            'division_id' => ['nullable', 'integer', Rule::exists('divisions', 'id')->where('organization_id', $org->id)],
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'consent' => 'accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'member_no.unique' => 'Nomor ini sudah terdaftar. Hubungi pengurus bila Anda belum pernah mendaftar.',
            'email.unique' => 'Email ini sudah terdaftar. Hubungi pengurus bila Anda belum pernah mendaftar.',
            'consent.accepted' => 'Centang persetujuan pemrosesan data untuk melanjutkan.',
        ];
    }

    public function register(Organization $org, Term $term, array $d, ?UploadedFile $photo): Member
    {
        $member = DB::transaction(function () use ($org, $term, $d, $photo) {
            $member = Member::create([
                'organization_id' => $org->id,
                'member_no' => trim($d['member_no']), 'name' => trim($d['name']), 'email' => Str::lower(trim($d['email'])),
                'phone' => $d['phone'] ?? null, 'cohort' => $d['cohort'] ?? null,
                'consented_at' => now(), 'consented_by' => null, // disetujui sendiri oleh pendaftar
                'photo_path' => $photo ? $this->photos->store($photo, "members/{$org->id}") : null,
            ]);
            Membership::create(['member_id' => $member->id, 'term_id' => $term->id, 'division_id' => $d['division_id'] ?? null, 'status' => 'applicant']);

            return $member;
        });
        AuditLogger::log('member.self_registered', 'member', $member->id);

        return $member;
    }
}
