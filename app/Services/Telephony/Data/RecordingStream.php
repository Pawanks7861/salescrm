<?php

namespace App\Services\Telephony\Data;

use Psr\Http\Message\StreamInterface;

/** Audio fetched from the provider (optionally a byte range), ready to be proxied. */
final class RecordingStream
{
    public function __construct(
        public readonly StreamInterface $body,
        public readonly int $status,
        public readonly string $mimeType,
        public readonly ?int $length,
        public readonly ?string $contentRange = null,
    ) {}
}
