<?php

namespace App\Services\Telephony\Data;

final class ProviderNumber
{
    public function __construct(
        public readonly ?string $providerNumberId,
        public readonly string $phoneNumber,
        public readonly ?string $displayName = null,
        public readonly string $numberType = 'virtual',
    ) {}
}
