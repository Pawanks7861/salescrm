<?php

namespace App\Services\Telephony\Data;

final class ProviderHealth
{
    public function __construct(
        public readonly bool $healthy,
        public readonly string $status,
        public readonly ?string $message = null,
    ) {}
}
