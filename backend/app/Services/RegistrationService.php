<?php

namespace App\Services;

use App\Models\Division;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    /** Organisasi tujuan pendaftaran dari app: satu-satunya organisasi di server ini (instans tunggal). */
    public function defaultOrganization(): ?Organization
    {
        return Organization::count() === 1 ? Organization::first() : null;
    }

    public function isOpen(?Organization $org): bool
    {
        return (bool) $org?->registration_token;
    }

    /** Akun app baru (peran Anggota) tanpa data anggota — e-profil diisi sesudahnya. */
    public function createAccount(Organization $org, array $d): User
    {
        $user = User::create([
            'organization_id' => $org->id, 'role' => User::MEMBER, 'member_id' => null,
            'name' => trim($d['name']), 'email' => Str::lower(trim($d['email'])), 'password' => $d['password'],
        ]);
        AuditLogger::log('user.self_registered', 'user', $user->id, [], $user->id);

        return $user;
    }

    /** Aturan e-profil (email diambil dari akun). */
    public function profileRules(Organization $org): array
    {
        return array_diff_key($this->rules($org), ['email' => true]);
    }

    /** Kirim e-profil: buat data anggota berstatus Pendaftar dan tautkan ke akun ini. */
    public function submitProfile(User $user, array $d, ?UploadedFile $photo): Member
    {
        abort_if($user->member_id, 422, 'E-profil sudah dikirim.');
        $org = Organization::findOrFail($user->organization_id);
        $term = $this->currentTerm($org) ?? abort(422, 'Belum ada periode aktif. Hubungi pengurus.');
        if (Member::where('organization_id', $org->id)->whereRaw('lower(email) = ?', [Str::lower($user->email)])->exists()) {
            throw ValidationException::withMessages(['email' => 'Email akun ini sudah terdaftar sebagai anggota. Minta pengurus menautkan akun Anda.']);
        }

        return DB::transaction(function () use ($user, $org, $term, $d, $photo) {
            $member = $this->register($org, $term, $d + ['email' => $user->email], $photo);
            $user->forceFill(['member_id' => $member->id, 'name' => $member->name])->save();

            return $member;
        });
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
        Notifier::later('newApplicant', $member->id);

        return $member;
    }
}
