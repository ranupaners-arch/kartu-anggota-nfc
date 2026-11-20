<?php

namespace App\Services\Push;

use App\Models\PushSubscription;

/** Pengirim Web Push (bisa diganti versi palsu di tes). */
interface PushTransport
{
    /**
     * Kirim satu pesan ke banyak langganan.
     *
     * @param  iterable<PushSubscription>  $subscriptions
     * @return list<string> endpoint_hash langganan yang sudah tidak berlaku (harus dihapus)
     */
    public function send(iterable $subscriptions, array $message): array;
}
