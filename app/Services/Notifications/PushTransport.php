<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;

interface PushTransport
{
    /**
     * @param  iterable<PushSubscription>  $subscriptions
     * @return array<int, array{ok: bool, expired: bool, status: int|null}> keyed by subscription id
     */
    public function send(iterable $subscriptions, string $payload): array;
}
