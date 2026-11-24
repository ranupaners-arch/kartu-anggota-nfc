<?php

namespace App\Services;

use App\Jobs\SendNotice;
use App\Mail\NoticeMail;
use App\Models\CardRequest;
use App\Models\Due;
use App\Models\DueItem;
use App\Models\Duty;
use App\Models\EventRsvp;
use App\Models\Member;
use App\Models\ProfileChange;
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

    public const EVENTS = ['membershipApproved', 'cardRequestDecided', 'newApplicant', 'newCardRequest', 'newProfileChange', 'profileChangeDecided', 'dutyGranted', 'dutyEnded', 'rsvpPromoted', 'dueCreated'];

    /** Jadwalkan lewat antrean sesudah commit (data sudah tersimpan saat dikirim). */
    public static function later(string $event, int $id): void
    {
        abort_unless(in_array($event, self::EVENTS, true), 500);
        SendNotice::dispatch($event, $id)->afterCommit();
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

    public function newProfileChange(int $changeId): void
    {
        $c = ProfileChange::withoutGlobalScopes()->with(['member' => fn ($q) => $q->withoutGlobalScopes()])->find($changeId);
        if ($c?->member) {
            $what = collect(['name' => 'nama', 'phone' => 'no. HP', 'photo_path' => 'foto'])->filter(fn ($l, $k) => filled($c->{$k}))->implode(', ');
            $this->send($this->superAdmins($c->member->organization_id), 'Perubahan data: '.$c->member->name, "{$c->member->name} ({$c->member->member_no}) mengajukan perubahan {$what}.", '/perubahan-data', 'Tinjau');
        }
    }

    public function profileChangeDecided(int $changeId): void
    {
        $c = ProfileChange::withoutGlobalScopes()->find($changeId);
        if (! $c) {
            return;
        }
        $note = $c->review_note ? " Catatan: {$c->review_note}" : '';
        [$title, $body] = $c->status === 'approved'
            ? ['Perubahan data disetujui', 'Data diri Anda sudah diperbarui.'.$note]
            : ['Perubahan data ditolak', 'Pengajuan perubahan data Anda ditolak pengurus.'.$note];
        $this->send($this->memberUsers($c->member_id), $title, $body, '/saya');
    }

    public function dutyGranted(int $dutyId): void
    {
        $d = Duty::withoutGlobalScopes()->with(['user' => fn ($q) => $q->withoutGlobalScopes(), 'division' => fn ($q) => $q->withoutGlobalScopes()])->find($dutyId);
        if ($d?->user && ! $d->ended_at) {
            $what = DutyService::LABELS[$d->type] ?? $d->type;
            $until = $d->ends_on ? ' sampai '.$d->ends_on->locale('id')->translatedFormat('j F Y') : ' sampai akhir periode';
            $this->send(collect([$d->user]), "Anda ditugaskan sebagai {$what}", "Tugas {$what}".($d->division ? " divisi {$d->division->name}" : '')."{$until}. Masuk lagi di app untuk membuka menu tugas.", '/');
        }
    }

    public function dutyEnded(int $dutyId): void
    {
        $d = Duty::withoutGlobalScopes()->with(['user' => fn ($q) => $q->withoutGlobalScopes()])->find($dutyId);
        if ($d?->user && $d->end_reason !== 'Diganti tugas baru') {
            $what = DutyService::LABELS[$d->type] ?? $d->type;
            $this->send(collect([$d->user]), "Tugas {$what} berakhir", "Terima kasih atas tugas Anda. Akun Anda kembali sebagai anggota ({$d->end_reason}).", '/saya');
        }
    }

    public function rsvpPromoted(int $rsvpId): void
    {
        $r = EventRsvp::withoutGlobalScopes()->with(['event' => fn ($q) => $q->withoutGlobalScopes()])->find($rsvpId);
        if ($r?->event && $r->status === EventRsvp::GOING) {
            $when = $r->event->starts_at->timezone(config('app.timezone'))->locale('id')->translatedFormat('j F Y H.i');
            $this->send($this->memberUsers($r->member_id), 'Anda dapat tempat: '.$r->event->title, "Anda naik dari daftar tunggu ({$when}). Bila batal ikut, ubah RSVP agar tempatnya dipakai anggota lain.", '/saya/kegiatan');
        }
    }

    /** Tagihan iuran baru: push saja (email massal tidak dikirim); di app muncul di lonceng. */
    public function dueCreated(int $dueId): void
    {
        $due = Due::withoutGlobalScopes()->find($dueId);
        if (! $due || $due->cancelled_at) {
            return;
        }
        $members = DueItem::withoutGlobalScopes()->where('due_id', $due->id)->where('status', DueItem::UNPAID)->select('member_id');
        $users = User::where('organization_id', $due->organization_id)->whereNull('disabled_at')->whereIn('member_id', $members)->pluck('id');
        $until = $due->due_on ? ' Jatuh tempo '.$due->due_on->locale('id')->translatedFormat('j F Y').'.' : '';
        $this->push->toUsers($users, 'Tagihan: '.$due->title, 'Rp'.number_format($due->amount, 0, ',', '.').'. Bayar ke bendahara.'.$until, '/saya/riwayat', 'due');
    }

    /** Semua akun tertaut ke anggota itu — termasuk anggota yang sedang bertugas (petugas/admin divisi). */
    private function memberUsers(int $memberId): Collection
    {
        return User::where('member_id', $memberId)->whereNull('disabled_at')->get();
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
