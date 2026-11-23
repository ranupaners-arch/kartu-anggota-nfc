<?php

namespace App\Services;

use App\Models\Member;
use App\Models\ProfileChange;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Anggota mengajukan perubahan data diri (nama, no. HP, foto) dari app/portal; data baru berlaku
 * setelah disetujui Super Admin. Satu pengajuan menunggu per anggota — pengajuan baru menggantikannya.
 */
class ProfileChangeService
{
    public function __construct(private PhotoService $photos) {}

    /** Status untuk app: data saat ini, pengajuan yang menunggu, dan keputusan terakhir (30 hari). */
    public function status(Member $member): array
    {
        $pending = ProfileChange::where('member_id', $member->id)->where('status', 'pending')->latest()->first();
        $last = ProfileChange::where('member_id', $member->id)->whereIn('status', ['approved', 'rejected'])
            ->where('reviewed_at', '>=', now()->subDays(30))->latest('reviewed_at')->first();

        return [
            'current' => ['name' => $member->name, 'phone' => $member->phone, 'has_photo' => (bool) $member->photo_path],
            'pending' => $pending ? $this->present($pending) : null,
            'last_decision' => $last ? $this->present($last) + ['review_note' => $last->review_note] : null,
        ];
    }

    public function request(Member $member, User $user, array $d, ?UploadedFile $photo): ProfileChange
    {
        abort_if($member->anonymized_at, 422, 'Data anggota ini sudah dihapus.');
        $name = isset($d['name']) ? trim($d['name']) : null;
        $phone = isset($d['phone']) ? trim($d['phone']) : null;
        $changes = array_filter([
            'name' => $name !== null && $name !== '' && $name !== $member->name ? $name : null,
            'phone' => $phone !== null && $phone !== '' && $phone !== $member->phone ? $phone : null,
        ]);
        if (! $changes && ! $photo) {
            throw ValidationException::withMessages(['name' => 'Tidak ada data yang berubah.']);
        }

        $change = DB::transaction(function () use ($member, $user, $d, $photo, $changes) {
            // Pengajuan baru menggantikan yang masih menunggu.
            foreach (ProfileChange::where('member_id', $member->id)->where('status', 'pending')->get() as $old) {
                $this->deletePhoto($old);
                $old->delete();
            }

            return ProfileChange::create($changes + [
                'member_id' => $member->id, 'user_id' => $user->id, 'reason' => $d['reason'] ?? null,
                'photo_path' => $photo ? $this->photos->store($photo, "profile-changes/{$member->organization_id}") : null,
            ]);
        });
        AuditLogger::log('profile_change.requested', 'member', $member->id, ['change_id' => $change->id]);
        Notifier::later('newProfileChange', $change->id);

        return $change;
    }

    public function review(User $reviewer, ProfileChange $change, bool $approve, ?string $note): void
    {
        abort_unless($change->status === 'pending', 422, 'Pengajuan ini sudah diputuskan.');
        DB::transaction(function () use ($reviewer, $change, $approve, $note) {
            $member = $change->member()->firstOrFail();
            if ($approve) {
                $member->update(array_filter(['name' => $change->name, 'phone' => $change->phone]));
                if ($change->photo_path) {
                    // Foto pengajuan dipindah jadi foto anggota; foto lama dihapus.
                    $old = $member->photo_path;
                    $member->update(['photo_path' => $change->photo_path]);
                    if ($old) {
                        Storage::disk('local')->delete($old);
                    }
                }
            } else {
                $this->deletePhoto($change);
            }
            $change->update([
                'status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $note,
                // Foto yang disetujui kini milik anggota; path pengajuan dikosongkan agar tidak ikut dihapus.
                'photo_path' => null,
            ]);
            AuditLogger::log($approve ? 'profile_change.approved' : 'profile_change.rejected', 'member', $member->id, ['change_id' => $change->id]);
        });
        Notifier::later('profileChangeDecided', $change->id);
    }

    private function present(ProfileChange $c): array
    {
        return $c->only('id', 'name', 'phone', 'reason', 'status') + [
            'has_photo' => (bool) $c->photo_path, 'created_at' => $c->created_at?->toIso8601String(), 'reviewed_at' => $c->reviewed_at?->toIso8601String(),
        ];
    }

    private function deletePhoto(ProfileChange $c): void
    {
        if ($c->photo_path) {
            Storage::disk('local')->delete($c->photo_path);
        }
    }
}
