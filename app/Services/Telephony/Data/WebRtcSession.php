<?php

namespace App\Services\Telephony\Data;

/**
 * Transient browser-calling session handed to the authenticated softphone.
 * Never logged, never put into Inertia props, never persisted client-side.
 */
final class WebRtcSession
{
    public function __construct(
        public readonly string $driver,
        public readonly ?string $sdkUrl,
        public readonly array $credentials,
    ) {}

    public function toArray(): array
    {
        return ['driver' => $this->driver, 'sdk_url' => $this->sdkUrl, 'credentials' => $this->credentials];
    }
}
