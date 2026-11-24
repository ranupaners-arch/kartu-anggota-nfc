<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Card;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\ExcuseRequest;
use App\Models\Member;
use App\Support\CardPayload;
use Illuminate\Support\Collection;

/**
 * Data satu kegiatan yang dipakai berulang saat memproses scan: target peserta, sesi, anggota
 * dengan izin disetujui — dimuat sekali per request/batch (bukan per scan). Untuk batch, kartu dan
 * absensi yang relevan juga dimuat di muka. Dibuat baru tiap request agar tidak pernah basi.
 */
class ScanContext
{
    /** @var Collection<int, EventSession> urut id; pertama = sesi utama */
    public readonly Collection $sessions;

    private bool $hasTargets;

    /** @var array<int, true> */
    private array $targetDivisions;

    /** @var array<int, true> */
    private array $targetMembers;

    /** @var array<int, true>|null */
    private ?array $approvedExcuses = null;

    /** @var array<string, Card|null> public_id → kartu (+ anggota, keanggotaan, divisi) */
    private array $cards = [];

    /** @var array<string, Attendance|null> client_uuid → absensi */
    private array $byClientUuid = [];

    /** @var array<string, Attendance|null> "sesi:anggota" → absensi */
    private array $bySessionMember = [];

    public function __construct(public readonly Event $event)
    {
        $this->sessions = $event->sessions()->orderBy('id')->get();
        $event->setRelation('sessions', $this->sessions);
        $targets = $event->targets()->get(['division_id', 'member_id']);
        $this->hasTargets = $targets->isNotEmpty();
        $this->targetDivisions = array_fill_keys($targets->pluck('division_id')->filter()->all(), true);
        $this->targetMembers = array_fill_keys($targets->pluck('member_id')->filter()->all(), true);
    }

    /** Muat di muka data untuk banyak scan sekaligus (sinkron batch offline). */
    public function prefetch(array $scans): void
    {
        $uuids = array_values(array_filter(array_column($scans, 'client_uuid'), 'is_string'));
        $this->byClientUuid = array_fill_keys($uuids, null); // belum ada di server = null (tanpa query lagi)
        foreach (array_chunk($uuids, 500) as $chunk) {
            foreach (Attendance::with('member')->whereIn('client_uuid', $chunk)->get() as $a) {
                $this->byClientUuid[$a->client_uuid] = $a;
            }
        }

        $ids = [];
        foreach ($scans as $s) {
            if (($s['source'] ?? 'nfc') !== 'qr' && ($p = CardPayload::verify((string) ($s['payload'] ?? '')))) {
                $ids[$p['public_id']] = true;
            }
        }
        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            $cards = Card::with('member.memberships.division')->whereIn('public_id', $chunk)->get()->keyBy('public_id');
            foreach ($chunk as $id) {
                $this->cards[$id] = $cards[$id] ?? null;
            }
        }

        $memberIds = collect($this->cards)->filter()->pluck('member_id')->unique()->values();
        foreach ($this->sessions as $s) {
            foreach ($memberIds as $m) {
                $this->bySessionMember["{$s->id}:{$m}"] = null;
            }
        }
        foreach ($memberIds->chunk(500) as $chunk) {
            foreach (Attendance::where('event_id', $this->event->id)->whereIn('member_id', $chunk)->get() as $a) {
                $this->bySessionMember["{$a->session_id}:{$a->member_id}"] = $a;
            }
        }
    }

    public function attendanceByClientUuid(string $uuid): ?Attendance
    {
        return array_key_exists($uuid, $this->byClientUuid) ? $this->byClientUuid[$uuid]
            : Attendance::with('member')->where('client_uuid', $uuid)->first();
    }

    public function card(string $publicId): ?Card
    {
        return array_key_exists($publicId, $this->cards) ? $this->cards[$publicId]
            : Card::with('member.memberships.division')->where('public_id', $publicId)->first();
    }

    public function attendance(EventSession $session, Member $member): ?Attendance
    {
        $key = "{$session->id}:{$member->id}";

        return array_key_exists($key, $this->bySessionMember) ? $this->bySessionMember[$key]
            : Attendance::where('session_id', $session->id)->where('member_id', $member->id)->first();
    }

    /** Catat absensi yang baru dibuat/diubah agar scan berikutnya dalam batch yang sama melihatnya. */
    public function remember(Attendance $a): void
    {
        $this->bySessionMember["{$a->session_id}:{$a->member_id}"] = $a;
        if ($a->client_uuid) {
            $this->byClientUuid[$a->client_uuid] = $a;
        }
    }

    /** Sama dengan Event::targetMembers(): keanggotaan aktif di periode kegiatan (+ divisi/anggota target). */
    public function isTarget(Member $member): bool
    {
        $member->loadMissing('memberships.division');
        $ms = $member->memberships->firstWhere('term_id', $this->event->term_id);
        if (! $ms || $ms->status !== 'active') {
            return false;
        }

        return ! $this->hasTargets || isset($this->targetMembers[$member->id]) || ($ms->division_id && isset($this->targetDivisions[$ms->division_id]));
    }

    public function hasApprovedExcuse(int $memberId): bool
    {
        $this->approvedExcuses ??= array_fill_keys(ExcuseRequest::where('event_id', $this->event->id)->where('status', 'approved')->pluck('member_id')->all(), true);

        return isset($this->approvedExcuses[$memberId]);
    }

    public function session(int $id): ?EventSession
    {
        return $this->sessions->firstWhere('id', $id);
    }

    public function multiSession(): bool
    {
        return $this->sessions->count() > 1;
    }
}
