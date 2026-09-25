<?php

namespace App\Services\Telephony\Data;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use Carbon\CarbonImmutable;

/**
 * A provider callback or call-detail lookup translated into CRM terms.
 * Provider-specific states never travel beyond this object.
 *
 * type: incoming (new inbound call) | status (progress/terminal update) | details (API lookup)
 */
final class ProviderCallEvent
{
    public const INCOMING = 'incoming';

    public const STATUS = 'status';

    public const DETAILS = 'details';

    public function __construct(
        public readonly string $type,
        public readonly string $providerCallId,
        public readonly ?CallStatus $status,
        public readonly ?string $providerStatus = null,
        public readonly ?CallDirection $direction = null,
        public readonly ?string $fromNumber = null,
        public readonly ?string $toNumber = null,
        public readonly ?string $virtualNumber = null,
        public readonly ?string $agentNumber = null,
        public readonly ?string $reference = null,
        public readonly ?CarbonImmutable $startedAt = null,
        public readonly ?CarbonImmutable $answeredAt = null,
        public readonly ?CarbonImmutable $endedAt = null,
        public readonly ?int $ringSeconds = null,
        public readonly ?int $talkSeconds = null,
        public readonly ?int $totalSeconds = null,
        public readonly ?string $recordingReference = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $providerEventId = null,
        public readonly ?CarbonImmutable $occurredAt = null,
        /** Sanitised copy of the provider payload (no credentials). */
        public readonly array $payload = [],
    ) {}

    /** Stable idempotency key: the same provider event always yields the same key. */
    public function dedupeKey(string $provider): string
    {
        $parts = [
            $provider,
            $this->providerCallId,
            $this->type,
            $this->providerEventId ?? '',
            $this->providerStatus ?? '',
            $this->status?->value ?? '',
            $this->recordingReference ? 'rec' : '',
        ];

        return $provider.':'.hash('sha256', implode('|', $parts));
    }
}
