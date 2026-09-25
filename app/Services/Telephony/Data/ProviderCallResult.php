<?php

namespace App\Services\Telephony\Data;

use App\Enums\CallStatus;

/** Result of a successfully accepted outbound request (acceptance ≠ connected). */
final class ProviderCallResult
{
    public function __construct(
        public readonly string $providerCallId,
        public readonly CallStatus $status,
        public readonly ?string $providerStatus = null,
    ) {}
}
