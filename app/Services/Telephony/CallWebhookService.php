<?php

namespace App\Services\Telephony;

use App\Enums\CallChannel;
use App\Enums\CallDirection;
use App\Enums\CallEventStatus;
use App\Enums\CallStatus;
use App\Models\Call;
use App\Models\CallEvent;
use App\Models\Lead;
use App\Models\TelephonyIntegration;
use App\Services\Leads\PhoneNormalizer;
use App\Services\Telephony\Data\ProviderCallEvent;
use App\Support\SecretRedactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ingests authenticated provider callbacks.
 *
 *  1. Ledger: every event is stored once in `call_events` (unique dedupe_key).
 *     A repeated delivery hits the unique key and is acknowledged as a
 *     duplicate without touching the call. Failed events may be re-processed.
 *  2. The call is located by provider call id, else by the CRM reference we
 *     handed to the provider (CustomField). Status callbacks for unknown
 *     outbound calls are ignored — a public payload can never create one.
 *     Incoming-call events create the inbound call.
 *  3. CallLifecycleService applies the event under a row lock.
 *
 * Only identifiers are logged — never payloads or phone numbers.
 */
class CallWebhookService
{
    public const PROCESSED = 'processed';

    public const DUPLICATE = 'duplicate';

    public const IGNORED = 'ignored';

    public function __construct(
        private readonly TelephonyManager $telephony,
        private readonly TelephonyDirectory $directory,
        private readonly CallLifecycleService $lifecycle,
        private readonly CallNumberService $numbers,
        private readonly PhoneNormalizer $phones,
    ) {}

    public function ingest(ProviderCallEvent $event, ?string $sourceIp = null): string
    {
        $provider = $this->telephony->provider()->name();
        $ledger = $this->claim($event, $provider, $sourceIp);
        if ($ledger === null) {
            return self::DUPLICATE;
        }

        try {
            $result = DB::transaction(fn () => $this->process($ledger, $event, $provider));
        } catch (Throwable $e) {
            $ledger->forceFill([
                'processing_status' => CallEventStatus::Failed,
                'failed_at' => now(),
                'error_message' => mb_substr(SecretRedactor::text($e::class.': '.$e->getMessage()), 0, 500),
            ])->save();
            Log::error('Telephony callback processing failed', ['event_id' => $ledger->id, 'provider_call_id' => $event->providerCallId, 'exception' => $e::class]);

            throw $e;
        }

        TelephonyIntegration::query()->where('provider', $provider)->update(['last_callback_at' => now()]);

        return $result;
    }

    /** Stores the event once; returns null when it was already received (and not failed). */
    private function claim(ProviderCallEvent $event, string $provider, ?string $ip): ?CallEvent
    {
        $key = $event->dedupeKey($provider);

        try {
            $ledger = new CallEvent;
            $ledger->forceFill([
                'provider' => $provider,
                'provider_call_id' => mb_substr($event->providerCallId, 0, 100),
                'provider_event_id' => $event->providerEventId ? mb_substr($event->providerEventId, 0, 100) : null,
                'dedupe_key' => $key,
                'event_type' => $event->type,
                'provider_status' => $event->providerStatus ? mb_substr($event->providerStatus, 0, 40) : null,
                'occurred_at' => $event->occurredAt,
                'received_at' => now(),
                'processing_status' => CallEventStatus::Received,
                'attempts' => 1,
                'payload_encrypted' => $event->payload,
                'source_ip' => $ip,
            ])->save();

            return $ledger;
        } catch (UniqueConstraintViolationException) {
            $existing = CallEvent::query()->where('dedupe_key', $key)->first();
            if ($existing && $existing->processing_status === CallEventStatus::Failed) {
                $existing->forceFill(['attempts' => $existing->attempts + 1, 'processing_status' => CallEventStatus::Received])->save();

                return $existing;
            }

            return null;
        }
    }

    private function process(CallEvent $ledger, ProviderCallEvent $event, string $provider): string
    {
        $call = $this->locate($event, $provider);

        if (! $call) {
            if ($event->type === ProviderCallEvent::INCOMING || $event->direction === CallDirection::Inbound) {
                $call = $this->createInbound($event, $provider);
            } else {
                $ledger->forceFill(['processing_status' => CallEventStatus::Ignored, 'processed_at' => now(), 'error_message' => 'unknown_call'])->save();

                return self::IGNORED;
            }
        }

        if ($call->direction === CallDirection::Inbound && $call->status->isOpen()) {
            $this->assignInboundAgent($call, $event);
        }

        $this->lifecycle->apply($call, $event);

        $ledger->forceFill(['call_id' => $call->id, 'processing_status' => CallEventStatus::Processed, 'processed_at' => now(), 'error_message' => null])->save();

        return self::PROCESSED;
    }

    private function locate(ProviderCallEvent $event, string $provider): ?Call
    {
        $call = Call::query()
            ->where('provider', $provider)
            ->where('provider_call_id', $event->providerCallId)
            ->lockForUpdate()
            ->first();

        if ($call || $event->reference === null || ! Str::isUuid($event->reference)) {
            return $call;
        }

        $call = Call::query()
            ->where('provider', $provider)
            ->where('client_reference', $event->reference)
            ->where('direction', CallDirection::Outbound->value)
            ->whereNull('provider_call_id')
            ->lockForUpdate()
            ->first();

        if ($call) {
            $call->provider_call_id = mb_substr($event->providerCallId, 0, 100);
        }

        return $call;
    }

    private function createInbound(ProviderCallEvent $event, string $provider): Call
    {
        $integration = $this->telephony->integration();
        $customer = $this->phones->normalize($event->fromNumber);
        $virtual = $event->virtualNumber ?? $event->toNumber;
        $number = $integration ? $this->directory->numberFor($integration, $virtual) : null;

        $matches = $customer
            ? Lead::query()
                ->where(fn ($q) => $q->where('normalized_phone', $customer)->orWhere('normalized_alternate_phone', $customer))
                ->limit(2)
                ->get(['id', 'assigned_to'])
            : collect();
        $lead = $matches->count() === 1 ? $matches->first() : null;

        $agent = $integration ? $this->directory->agentByDialledLeg($integration, $event->agentNumber)?->user : null;
        $agentId = $agent?->id ?? $lead?->assigned_to;

        $call = new Call;
        $call->forceFill([
            'call_number' => $this->numbers->next(),
            'client_reference' => (string) Str::uuid(),
            'lead_id' => $lead?->id,
            'agent_user_id' => $agentId,
            'integration_id' => $integration?->id,
            'telephony_number_id' => $number?->id,
            'provider' => $provider,
            'provider_call_id' => mb_substr($event->providerCallId, 0, 100),
            'direction' => CallDirection::Inbound,
            'channel' => CallChannel::Provider,
            'from_number' => $event->fromNumber ? mb_substr($event->fromNumber, 0, 30) : null,
            'from_number_normalized' => $customer,
            'to_number' => $virtual ? mb_substr($virtual, 0, 30) : null,
            'to_number_normalized' => $this->phones->normalize($virtual),
            'customer_number_normalized' => $customer,
            'virtual_number' => $virtual ? mb_substr($virtual, 0, 30) : null,
            'status' => CallStatus::Initiated,
            'started_at' => $event->startedAt ?? $event->occurredAt ?? now(),
        ])->save();

        return $call;
    }

    /** The provider's dialled leg identifies who handled an inbound call. */
    private function assignInboundAgent(Call $call, ProviderCallEvent $event): void
    {
        $integration = $call->integration;
        if (! $integration || $event->agentNumber === null) {
            return;
        }

        $agent = $this->directory->agentByDialledLeg($integration, $event->agentNumber)?->user;
        if ($agent && (int) $call->agent_user_id !== $agent->id) {
            $call->agent_user_id = $agent->id;
            if ($event->agentNumber && str_starts_with(strtolower($event->agentNumber), 'sip:')) {
                $call->channel = CallChannel::WebRtc;
            }
        }
    }
}
