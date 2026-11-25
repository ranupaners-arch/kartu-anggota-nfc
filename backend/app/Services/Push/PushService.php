<?php

namespace App\Services\Push;

use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\Membership;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Notifikasi push portal anggota: pengingat kegiatan & pengumuman. Nonaktif selama kunci VAPID kosong.
 * Dipanggil dari scheduler/queue (tanpa login), jadi organisasi selalu dibatasi eksplisit.
 */
class PushService
{
    public const REMIND_BEFORE_MINUTES = 60;

    public function __construct(private PushTransport $transport) {}

    public static function enabled(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    public function subscribe(User $user, array $d, ?string $userAgent): PushSubscription
    {
        return PushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $d['endpoint'])], [
            'user_id' => $user->id, 'endpoint' => $d['endpoint'],
            'p256dh' => $d['keys']['p256dh'], 'auth' => $d['keys']['auth'], 'user_agent' => mb_substr((string) $userAgent, 0, 300),
        ]);
    }

    public function unsubscribe(User $user, string $endpoint): void
    {
        PushSubscription::where('user_id', $user->id)->where('endpoint_hash', hash('sha256', $endpoint))->delete();
    }

    /** Kirim ke semua perangkat milik pengguna ini; langganan yang kedaluwarsa dihapus. @return int jumlah perangkat */
    public function toUsers(Collection $userIds, string $title, string $body, string $url = '/saya', ?string $tag = null): int
    {
        FcmService::wakeLater($userIds); // app Android
        if (! self::enabled() || $userIds->isEmpty()) {
            return 0;
        }
        $subs = PushSubscription::whereIn('user_id', $userIds->unique())->get();
        if ($subs->isEmpty()) {
            return 0;
        }
        $expired = $this->transport->send($subs, ['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag]);
        PushSubscription::whereIn('endpoint_hash', $expired)->delete();
        PushSubscription::whereIn('id', $subs->pluck('id'))->whereNotIn('endpoint_hash', $expired)->update(['last_used_at' => now()]);

        return $subs->count() - count($expired);
    }

    /** Pengumuman baru → anggota organisasi itu (dibatasi divisi bila pengumuman untuk satu divisi). */
    public function announcement(Announcement $a): int
    {
        $memberIds = Membership::where('status', 'active')
            ->whereHas('term', fn ($t) => $t->withoutGlobalScopes()->where('organization_id', $a->organization_id)->where('is_current', true))
            ->when($a->division_id, fn ($q) => $q->where('division_id', $a->division_id))
            ->pluck('member_id');
        // Termasuk anggota yang sedang bertugas (akun tertaut ke anggota, apa pun perannya).
        $users = User::where('organization_id', $a->organization_id)->whereNull('disabled_at')
            ->whereIn('member_id', $memberIds)->pluck('id');

        return $this->toUsers($users, 'Pengumuman: '.$a->title, str($a->body)->squish()->limit(140)->toString(), '/saya/kegiatan', "pengumuman-{$a->id}");
    }

    /**
     * Pengingat ±1 jam sebelum kegiatan dimulai, sekali per kegiatan, untuk peserta yang belum hadir/izin.
     *
     * @return array{events:int, devices:int}
     */
    public function eventReminders(): array
    {
        if (! self::enabled()) {
            return ['events' => 0, 'devices' => 0];
        }
        $events = Event::withoutGlobalScopes()->whereNull('closed_at')
            ->whereBetween('starts_at', [now(), now()->addMinutes(self::REMIND_BEFORE_MINUTES)])->get();
        $sent = ['events' => 0, 'devices' => 0];
        foreach ($events as $e) {
            if (! Cache::add("push:reminded:{$e->id}", true, now()->addDay())) {
                continue; // sudah diingatkan
            }
            $done = Attendance::where('event_id', $e->id)->whereNull('voided_at')->pluck('member_id')
                ->merge(ExcuseRequest::where('event_id', $e->id)->whereIn('status', ['pending', 'approved'])->pluck('member_id'));
            $memberIds = $e->targetMembers()->pluck('members.id')->diff($done);
            $users = User::where('organization_id', $e->organization_id)->whereNull('disabled_at')
                ->whereIn('member_id', $memberIds)->pluck('id');
            $body = 'Mulai '.$e->starts_at->timezone('Asia/Jakarta')->format('H.i').($e->location ? ' · '.$e->location : '').' · bawa kartu anggota';
            $sent['devices'] += $this->toUsers($users, 'Pengingat: '.$e->title, $body, '/saya', "kegiatan-{$e->id}");
            $sent['events']++;
        }

        return $sent;
    }
}
