<?php

namespace App\Services\Meta;

use App\Enums\MetaErrorCategory;
use App\Support\SecretRedactor;
use RuntimeException;

/**
 * A classified, sanitized integration failure. The message is safe to store
 * and show to administrators; it never contains tokens or request URLs.
 */
class MetaApiException extends RuntimeException
{
    public function __construct(
        public readonly MetaErrorCategory $category,
        string $message = '',
        public readonly ?string $metaCode = null,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct(mb_substr(SecretRedactor::text($message !== '' ? $message : $category->message()), 0, 450));
    }

    public static function of(MetaErrorCategory $category, ?string $message = null, ?string $code = null): self
    {
        return new self($category, $message ?? $category->message(), $code);
    }

    public function isRetryable(): bool
    {
        return $this->category->isRetryable();
    }

    /** Short identifier stored as the event error code, e.g. "meta_190:463". */
    public function code(): string
    {
        return $this->metaCode ?? $this->category->value;
    }
}
