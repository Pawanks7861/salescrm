<?php

namespace App\Services\Telephony;

use RuntimeException;

/**
 * Provider/configuration failure with a message that is safe to show users.
 * Technical detail (already redacted) is kept separately for the log.
 */
class TelephonyException extends RuntimeException
{
    public const UNAVAILABLE = 'unavailable';

    public const NOT_CONFIGURED = 'not_configured';

    public const REJECTED = 'rejected';

    public const NOT_FOUND = 'not_found';

    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($message);
    }

    public static function unavailable(?string $detail = null): self
    {
        return new self(self::UNAVAILABLE, 'Calling service is temporarily unavailable.', $detail);
    }

    public static function notConfigured(string $message = 'Calling is not configured yet. Please contact your administrator.'): self
    {
        return new self(self::NOT_CONFIGURED, $message);
    }

    public static function rejected(string $message, ?string $detail = null): self
    {
        return new self(self::REJECTED, $message, $detail);
    }
}
