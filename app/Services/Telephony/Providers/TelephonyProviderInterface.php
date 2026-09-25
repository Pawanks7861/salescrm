<?php

namespace App\Services\Telephony\Providers;

use App\Enums\CallStatus;
use App\Models\TelephonyUser;
use App\Services\Telephony\Data\OutboundCallRequest;
use App\Services\Telephony\Data\ProviderCallEvent;
use App\Services\Telephony\Data\ProviderCallResult;
use App\Services\Telephony\Data\ProviderHealth;
use App\Services\Telephony\Data\ProviderNumber;
use App\Services\Telephony\Data\RecordingStream;
use App\Services\Telephony\Data\WebhookValidation;
use App\Services\Telephony\Data\WebRtcSession;
use App\Services\Telephony\TelephonyException;
use Illuminate\Http\Request;

/**
 * Boundary between the CRM and a telephony provider. Implementations translate
 * provider formats and states into the provider-neutral DTOs above; nothing
 * provider-specific may cross this interface. Only the telephony services use
 * it — never controllers.
 */
interface TelephonyProviderInterface
{
    /** Short provider key stored on calls (e.g. "exotel"). */
    public function name(): string;

    /** All required credentials are present (does not contact the provider). */
    public function isConfigured(): bool;

    /** Whether browser calling can be offered (SDK + token configured). */
    public function supportsWebRtc(): bool;

    /** @throws TelephonyException */
    public function initiateOutboundCall(OutboundCallRequest $request): ProviderCallResult;

    /** @throws TelephonyException */
    public function getCall(string $providerCallId): ?ProviderCallEvent;

    /** @throws TelephonyException */
    public function getCallStatus(string $providerCallId): ?CallStatus;

    /** @throws TelephonyException */
    public function createWebRtcSession(TelephonyUser $agent): WebRtcSession;

    /**
     * Fetches recording audio for a stored reference, forwarding an optional
     * HTTP Range header.
     *
     * @throws TelephonyException
     */
    public function getRecording(string $reference, ?string $range = null): RecordingStream;

    /** Whether a recording reference may be fetched at all (host allow-list). */
    public function acceptsRecordingReference(string $reference): bool;

    /**
     * Absolute callback URL (including any provider-specific authenticity
     * token) for "status" or "passthru". Never shown to users or logged.
     */
    public function callbackUrl(string $endpoint): string;

    public function validateWebhook(Request $request): WebhookValidation;

    /**
     * @param  string  $endpoint  "status" (call progress callbacks) or "passthru" (incoming call flow)
     */
    public function parseWebhook(Request $request, string $endpoint): ?ProviderCallEvent;

    /**
     * @return array<ProviderNumber>
     *
     * @throws TelephonyException
     */
    public function getNumbers(): array;

    public function healthCheck(): ProviderHealth;
}
