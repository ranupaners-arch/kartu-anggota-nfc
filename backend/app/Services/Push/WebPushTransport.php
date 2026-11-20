<?php

namespace App\Services\Push;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushTransport implements PushTransport
{
    public function send(iterable $subscriptions, array $message): array
    {
        $push = new WebPush(['VAPID' => [
            'subject' => config('services.webpush.subject'),
            'publicKey' => config('services.webpush.public_key'),
            'privateKey' => config('services.webpush.private_key'),
        ]], ['TTL' => 3600, 'urgency' => 'high']);

        $byEndpoint = [];
        foreach ($subscriptions as $s) {
            $byEndpoint[$s->endpoint] = $s->endpoint_hash;
            $push->queueNotification(Subscription::create([
                'endpoint' => $s->endpoint, 'keys' => ['p256dh' => $s->p256dh, 'auth' => $s->auth],
            ]), json_encode($message, JSON_UNESCAPED_UNICODE));
        }

        $expired = [];
        foreach ($push->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                $expired[] = $byEndpoint[$report->getEndpoint()] ?? null;
            } elseif (! $report->isSuccess()) {
                report(new \RuntimeException('Web Push gagal: '.$report->getReason()));
            }
        }

        return array_values(array_filter($expired));
    }
}
