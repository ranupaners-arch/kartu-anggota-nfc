<?php

namespace App\Jobs;

use App\Services\Push\FcmService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Bangunkan HP; hanya yang gagal sementara yang dicoba lagi. */
class WakePhones implements ShouldQueue
{
    use Queueable;

    private const MAX_ROUNDS = 3;

    public int $tries = 3;

    public int $backoff = 15;

    public int $timeout = 120;

    /**
     * @param  list<int>  $userIds
     * @param  list<int>  $deviceIds
     */
    public function __construct(public array $userIds, public array $deviceIds = [], public int $round = 1) {}

    public function handle(FcmService $fcm): void
    {
        $failed = $this->deviceIds ? $fcm->retry($this->deviceIds) : $fcm->wake($this->userIds);
        if ($failed && $this->round < self::MAX_ROUNDS) {
            self::dispatch([], $failed, $this->round + 1)->delay(now()->addSeconds(20 * $this->round));
        }
    }
}
