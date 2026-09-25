<?php

namespace App\Services\Telephony\Data;

/** Provider-neutral click-to-call request. Numbers are digits-only international form. */
final class OutboundCallRequest
{
    public function __construct(
        public readonly string $agentNumber,
        public readonly string $customerNumber,
        public readonly ?string $callerId,
        public readonly string $reference,
        public readonly bool $record,
        public readonly string $statusCallbackUrl,
    ) {}
}
