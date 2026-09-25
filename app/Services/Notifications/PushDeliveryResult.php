<?php

namespace App\Services\Notifications;

/**
 * Outcome of one push attempt, by subscription id. `retry` holds browsers
 * that failed for a temporary reason (network, 429, 5xx); expired ones are
 * already deleted and permanent rejections are not retried.
 */
final class PushDeliveryResult
{
    /**
     * @param  list<int>  $delivered
     * @param  list<int>  $retry
     */
    public function __construct(
        public readonly array $delivered = [],
        public readonly array $retry = [],
        public readonly int $removed = 0,
    ) {}
}
