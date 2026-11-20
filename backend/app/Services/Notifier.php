<?php

namespace App\Services;

use App\Mail\NoticeMail;
use App\Models\CardRequest;
use App\Models\Member;
use App\Models\User;
use App\Services\Push\PushService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Pemberitahuan alur kartu anggota lewat push (portal/dashboard, bila VAPID diisi) dan email (bila SMTP
 * diatur). Dikirim lewat antrean setelah transaksi selesai; dari antrean tidak ada sesi login, jadi
 * penerima dibatasi organisasi secara eksplisit.
 */
class Notifier
{
    public function __construct(private PushService $push) {}

    public const EVENTS = ['membershipApproved', 'cardRequestDecided', 'newApplicant', 'newCardRequest'];

    /** Jadwalkan lewat antrean sesudah commit (data sudah tersimpan saat dikirim). */
    public static function later(string $event, int $id): void
    {
        abort_unless(in_array($event, self::EVENTS, true), 500);
        \App\Jobs\SendNotice::dispatch($event, $id)->afterCommit();
    }

    public function membershipApproved(int $memberId): void
    {
        $this->send($this->memberUsers($memberId), 'Keanggotaan disahkan', 'E-profil Anda sudah disahkan pengurus. Kartu anggota Anda kini aktif di app/portal.', '/saya');
    }

    public function cardRequestDecided(int $requestId): void
    {
        $req = CardRequest::withoutGlobalScopes()->find($requestId);
        if (! $req) {
            return;
        }
        $note = $req->review_note ? " Catatan: {$req->review_note}" : '';
        [$title, $body] = match (true) {
            $req->status === 'rejected' => ['Laporan kartu ditolak', 'Laporan kartu Anda ditolak pengurus.'.$note],
            $req->resolution === 'admin' => ['Kartu Anda akan diterbitkan pengurus', 'Pengurus menyiapkan kartu baru untuk Anda.'.$note],
            default => ['Laporan kartu disetujui', 'Anda bisa mendaftarkan kartu NFC baru di app (tab Kartu).'.$note],
        };
        $this->send($this->memberUsers($req->member_id), $title, $body, '/saya');
    }

    public function newApplicant(int $memberId): void
    {
        $m = Member::withoutGlobalScopes()->find($memberId);
        if ($m) {
            $this->send($this->superAdmins($m->organization_id), 'Pendaftar baru: '.$m->name, "{$m->name} ({$m->member_no}) mengirim pendaftaran dan menunggu pengesahan.", '/anggota?tab=pendaftar', 'Tinjau pendaftar');
        }
    }

    public function newCardRequest(int $requestId): void
    {
        $req = CardRequest::withoutGlobalScopes()->with(['member' => fn ($q) => $q->withoutGlobalScopes()])->find($requestId);
        if ($req?->member) {
            $what = ['hilang' => 'kartu hilang', 'rusak' => 'kartu rusak', 'tanpa_nfc' => 'HP tanpa NFC'][$req->reason] ?? $req->reason;
            $this->send($this->superAdmins($req->member->organization_id), 'Laporan kartu: '.$req->member->name, "{$req->member->name} ({$req->member->member_no}) melapor {$what}.".($req->note ? " \"{$req->note}\"" : ''), '/kartu?tab=pengganti', 'Tindak lanjuti');
        }
    }

    private function memberUsers(int $memberId): Collection
    {
        return User::where('member_id', $memberId)->where('role', User::MEMBER)->whereNull('disabled_at')->get();
    }

    private function superAdmins(int $organizationId): Collection
    {
        return User::where('organization_id', $organizationId)->where('role', User::SUPER_ADMIN)->whereNull('disabled_at')->get();
    }

    private function send(Collection $users, string $title, string $body, string $path, string $action = 'Buka'): void
    {
        if ($users->isEmpty()) {
            return;
        }
        $this->push->toUsers($users->pluck('id'), $title, $body, $path, 'notice');
        if (MailStatus::enabled()) {
            $url = rtrim(config('app.url'), '/').$path;
            foreach ($users->filter(fn (User $u) => filled($u->email)) as $u) {
                try {
                    Mail::to($u->email)->send(new NoticeMail($title, $body, $url, $action));
                } catch (\Throwable $e) {
                    report($e); // email gagal tidak boleh menggagalkan alur utama
                }
            }
        }
    }
}
