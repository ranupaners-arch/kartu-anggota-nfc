<?php

namespace App\Services;

use App\Models\Division;
use App\Models\Duty;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tugas pengurus pada akun anggota. Peran akun (kolom users.role, dipakai seluruh pemeriksaan hak akses)
 * mengikuti tugas aktif: diberi tugas → scanner/division_admin; tugas dicabut/berakhir → member.
 * Setiap perubahan peran mencabut sesi app akun itu, sehingga HP-nya menyesuaikan tampilan.
 * Hanya akun yang tertaut ke anggota; akun bersama (mis. HP sekretariat) tetap diatur di Pengguna & Peran.
 */
class DutyService
{
    public const LABELS = [User::SCANNER => 'Petugas Scan', User::DIVISION_ADMIN => 'Admin Divisi'];

    public function current(User $user): ?Duty
    {
        return Duty::open()->where('user_id', $user->id)->latest('id')->first();
    }

    /** @return Collection<int, Duty> tugas aktif di periode berjalan */
    public function active(): Collection
    {
        $term = Term::where('is_current', true)->first();

        return $term ? Duty::open()->where('term_id', $term->id)->with('user:id,name,email,member_id,role', 'user.member:id,member_no', 'division:id,name', 'granter:id,name')->get() : collect();
    }

    /** Beri (atau ganti) tugas. Satu tugas aktif per akun; tugas lama diakhiri. */
    public function grant(User $actor, User $user, string $type, ?int $divisionId = null, ?string $endsOn = null): Duty
    {
        abort_unless($user->organization_id === $actor->organization_id, 404);
        if (! in_array($type, Duty::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Jenis tugas tidak dikenal.']);
        }
        if (! $user->member_id) {
            throw ValidationException::withMessages(['user' => 'Tugas hanya untuk akun yang tertaut ke anggota.']);
        }
        if ($user->role === User::SUPER_ADMIN) {
            throw ValidationException::withMessages(['user' => 'Super Admin tidak perlu tugas.']);
        }
        if ($user->disabled_at) {
            throw ValidationException::withMessages(['user' => 'Akun ini nonaktif.']);
        }
        if ($type === User::DIVISION_ADMIN && ! ($divisionId && Division::whereKey($divisionId)->exists())) {
            throw ValidationException::withMessages(['division_id' => 'Pilih divisi untuk Admin Divisi.']);
        }
        $term = Term::where('is_current', true)->first() ?? throw ValidationException::withMessages(['type' => 'Belum ada periode berjalan.']);
        if ($endsOn && Carbon::parse($endsOn)->lt(today())) {
            throw ValidationException::withMessages(['ends_on' => 'Tanggal berakhir sudah lewat.']);
        }

        $duty = DB::transaction(function () use ($actor, $user, $type, $divisionId, $endsOn, $term) {
            Duty::open()->where('user_id', $user->id)->update(['ended_at' => now(), 'ended_by' => $actor->id, 'end_reason' => 'Diganti tugas baru']);

            $duty = Duty::create([
                'organization_id' => $user->organization_id, 'user_id' => $user->id, 'term_id' => $term->id, 'type' => $type,
                'division_id' => $type === User::DIVISION_ADMIN ? $divisionId : null, 'ends_on' => $endsOn, 'granted_by' => $actor->id,
            ]);
            $this->applyRole($user, $type, $duty->division_id);
            AuditLogger::log('duty.granted', 'user', $user->id, ['duty_id' => $duty->id, 'type' => $type]);

            return $duty;
        });
        Notifier::later('dutyGranted', $duty->id);

        return $duty;
    }

    /** Cabut tugas (mengundurkan diri / tidak bertugas lagi) → akun kembali menjadi anggota. */
    public function end(?User $actor, Duty $duty, string $reason): void
    {
        abort_if($duty->ended_at, 422, 'Tugas ini sudah berakhir.');
        DB::transaction(function () use ($actor, $duty, $reason) {
            $duty->update(['ended_at' => now(), 'ended_by' => $actor?->id, 'end_reason' => $reason]);
            $user = $duty->user()->withoutGlobalScopes()->first();
            if ($user && ! Duty::open()->withoutGlobalScopes()->where('user_id', $user->id)->exists()) {
                $this->applyRole($user, User::MEMBER, null);
            }
            AuditLogger::log('duty.ended', 'user', $duty->user_id, ['duty_id' => $duty->id, 'reason' => $reason], $actor?->id);
        });
        Notifier::later('dutyEnded', $duty->id);
    }

    /** Perpanjang tugas dari periode sebelumnya ke periode berjalan (jenis & divisi sama). */
    public function renew(User $actor, Duty $old): Duty
    {
        $user = $old->user()->firstOrFail();

        return $this->grant($actor, $user, $old->type, $old->division_id);
    }

    /**
     * Akhiri tugas yang masa berlakunya habis: periodenya bukan periode berjalan lagi, atau melewati
     * tanggal berakhir. Dipanggil terjadwal & saat periode berjalan diganti. Mengembalikan jumlahnya.
     */
    public function expire(): int
    {
        $current = Term::withoutGlobalScopes()->where('is_current', true)->pluck('id');
        $due = Duty::withoutGlobalScopes()->open()
            ->where(fn ($q) => $q->whereNotIn('term_id', $current)->orWhereDate('ends_on', '<', today()))
            ->get();
        foreach ($due as $duty) {
            $this->end(null, $duty, $duty->ends_on && $duty->ends_on->lt(today()) ? 'Masa tugas berakhir' : 'Periode berganti');
        }

        return $due->count();
    }

    /** Ubah peran akun + cabut sesi app-nya bila berubah (hak akses baru langsung berlaku). */
    private function applyRole(User $user, string $role, ?int $divisionId): void
    {
        $user->forceFill(['role' => $role, 'division_id' => $role === User::DIVISION_ADMIN ? $divisionId : null]);
        if ($user->isDirty(['role', 'division_id'])) {
            $user->save();
            $user->tokens()->delete();
        }
    }
}
