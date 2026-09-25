<?php

namespace App\Services\Notifications;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** Standard Web Push (RFC 8030 / 8291 / 8292 VAPID) via minishlink/web-push. */
class MinishlinkPushTransport implements PushTransport
{
    public function send(iterable $subscriptions, string $payload): array
    {
        PushEncryptionUnavailable::check();

        $webPush = new WebPush(
            ['VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ]],
            ['TTL' => (int) config('webpush.ttl', 3600), 'urgency' => 'high'],
            15,
        );
        $webPush->setReuseVAPIDHeaders(true);

        $byEndpoint = [];
        foreach ($subscriptions as $subscription) {
            $byEndpoint[$subscription->endpoint] = $subscription->id;
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => $subscription->content_encoding,
            ]), $payload);
        }

        $results = [];
        foreach ($webPush->flush() as $report) {
            $id = $byEndpoint[$report->getEndpoint()] ?? null;
            if ($id !== null) {
                $results[$id] = [
                    'ok' => $report->isSuccess(),
                    'expired' => $report->isSubscriptionExpired(),
                    'status' => $report->getResponse()?->getStatusCode(),
                ];
            }
        }

        return $results;
    }
}
