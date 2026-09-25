<?php

namespace App\Services\Telephony\Data;

final class WebhookValidation
{
    private function __construct(public readonly bool $valid, public readonly ?string $reason) {}

    public static function ok(): self
    {
        return new self(true, null);
    }

    public static function reject(string $reason): self
    {
        return new self(false, $reason);
    }
}
