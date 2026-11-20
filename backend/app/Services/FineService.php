<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Card;
use App\Models\Event;
use App\Models\Fine;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Kebijakan denda (diatur Super Admin): absen pakai QR padahal anggota punya kartu NFC aktif (kartu
 * tertinggal) → denda per kejadian, setelah jatah QR gratis per periode habis. Anggota yang belum punya
 * kartu, atau kartunya sudah dilapor hilang (otomatis diblokir), tidak didenda.
 */
class FineService
{
    /** Dipanggil setelah absensi QR berhasil. Mengembalikan nominal denda (0 = tidak ada). */
    public function onQrAttendance(Attendance $a, Event $event): int
    {
        if ($a->source !== 'qr' || ! in_array($a->status, [Attendance::HADIR, Attendance::TELAT], true)) {
            return 0;
        }
        $org = Organization::find($event->organization_id);
        if (! $org?->fine_enabled || Fine::where('attendance_id', $a->id)->exists()) {
            return 0;
        }
        $hasCard = Card::withoutGlobalScopes()->where('member_id', $a->member_id)->where('status', Card::ACTIVE)->exists();
        if (! $hasCard) {
            return 0;
        }
        $used = Fine::where('member_id', $a->member_id)->where('term_id', $event->term_id)->count();
        $free = $used < $org->fine_free_per_term;
        $amount = $free ? 0 : (int) $org->fine_amount;
        Fine::create([
            'member_id' => $a->member_id, 'attendance_id' => $a->id, 'term_id' => $event->term_id,
            'amount' => $amount, 'status' => $free || $amount === 0 ? Fine::FREE : Fine::UNPAID,
        ]);

        return $amount;
    }

    /** Absensi dibatalkan/diubah bukan hadir → denda terkait otomatis dibebaskan. */
    public function onAttendanceCorrected(Attendance $a, User $by): void
    {
        if ($a->voided_at || ! in_array($a->status, [Attendance::HADIR, Attendance::TELAT], true)) {
            Fine::where('attendance_id', $a->id)->where('status', Fine::UNPAID)
                ->update(['status' => Fine::WAIVED, 'note' => 'Absensi dikoreksi', 'handled_by' => $by->id, 'handled_at' => now()]);
        }
    }

    /** Tandai lunas / bebaskan / kembalikan ke belum lunas (banyak sekaligus). @return int jumlah diubah */
    public function mark(User $by, array $ids, string $status, ?string $note): int
    {
        abort_unless(in_array($status, [Fine::PAID, Fine::WAIVED, Fine::UNPAID], true), 422);

        return DB::transaction(function () use ($by, $ids, $status, $note) {
            $fines = Fine::whereIn('id', $ids)->where('status', '!=', Fine::FREE)->get();
            foreach ($fines as $f) {
                $f->update(['status' => $status, 'note' => $note ?? $f->note, 'handled_by' => $by->id, 'handled_at' => now()]);
            }
            AuditLogger::log("fine.$status", 'fine', null, ['ids' => $fines->pluck('id')]);

            return $fines->count();
        });
    }

    /** Ringkasan untuk anggota: total belum lunas + daftar periode ini. */
    public function forMember(int $memberId, ?int $termId): array
    {
        $fines = Fine::with('attendance.event:id,title,starts_at')->where('member_id', $memberId)
            ->when($termId, fn ($q) => $q->where('term_id', $termId))->where('status', '!=', Fine::FREE)->latest()->get();

        return [
            'unpaid_total' => (int) $fines->where('status', Fine::UNPAID)->sum('amount'),
            'items' => $fines->take(30)->map(fn (Fine $f) => [
                'id' => $f->id, 'amount' => $f->amount, 'status' => $f->status, 'note' => $f->note,
                'event' => $f->attendance?->event?->title, 'date' => $f->attendance?->event?->starts_at,
            ])->values(),
        ];
    }
}
