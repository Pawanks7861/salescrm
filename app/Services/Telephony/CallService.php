<?php

namespace App\Services\Telephony;

use App\Enums\AuditAction;
use App\Enums\CallChannel;
use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\TelephonyCallingMode;
use App\Models\Call;
use App\Models\Lead;
use App\Models\TelephonyIntegration;
use App\Models\TelephonyUser;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\AuditService;
use App\Services\Leads\LeadVisibility;
use App\Services\Leads\PhoneNormalizer;
use App\Services\Telephony\Data\OutboundCallRequest;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Starts outbound calls. The destination is always resolved on the server:
 * from the authorised lead's phone / alternate phone, or — only for holders
 * of call.manual_dial — from a typed number that is normalised and logged.
 * The agent is always the authenticated user.
 *
 * Click-to-call: provider request first (no DB transaction held open), then
 * the call row is persisted with the provider's call id. Nothing is written
 * when the provider refuses, so a failed request never looks like a call.
 *
 * Browser calling: the call row is created first (status initiated) and its
 * reference is handed to the softphone, which dials through the provider SDK
 * with that reference as CustomField. Provider callbacks — not the browser —
 * move the call forward.
 */
class CallService
{
    public const CONTACT_FIELDS = ['phone', 'alternate_phone'];

    public function __construct(
        private readonly TelephonyManager $telephony,
        private readonly TelephonyDirectory $directory,
        private readonly CallNumberService $numbers,
        private readonly LeadVisibility $leads,
        private readonly PhoneNormalizer $phones,
        private readonly ActivityService $activities,
        private readonly AuditService $audit,
    ) {}

    /**
     * `$input`: contact_field (phone|alternate_phone) or number (manual dial), mode (webrtc|pstn)?
     *
     * @return array{call: Call, dial: ?array{number: string, reference: string}}
     *
     * @throws AuthorizationException|ModelNotFoundException|ValidationException|TelephonyException
     */
    public function startOutbound(User $actor, ?Lead $lead, array $input): array
    {
        if (! $actor->is_active || ! $actor->hasPermission(Permissions::CALL_MAKE)) {
            throw new AuthorizationException('You are not allowed to make calls.');
        }

        if ($lead !== null && ($lead->trashed() || ! $this->leads->canView($actor, $lead))) {
            throw (new ModelNotFoundException)->setModel(Lead::class, [$lead->id]);
        }

        [$number, $contactField] = $this->resolveDestination($actor, $lead, $input);

        $integration = $this->telephony->activeIntegration();
        $provider = $this->telephony->provider();
        if (! $integration || ! $provider->isConfigured()) {
            throw TelephonyException::notConfigured();
        }

        $agent = $this->directory->agentFor($integration, $actor);
        if (! $agent) {
            throw TelephonyException::notConfigured('Your calling account is not set up yet. Please contact your administrator.');
        }

        $mode = $this->resolveMode($integration, $agent, $input['mode'] ?? null);
        $callerNumber = $this->directory->outboundNumber($integration, $actor);
        $callerId = $callerNumber?->phone_number ?? (config('telephony.driver') === 'exotel' ? config('telephony.exotel.default_caller_id') : null);
        $reference = (string) Str::uuid();

        if ($mode === TelephonyCallingMode::WebRtc) {
            $call = $this->persist($actor, $lead, $integration, $callerNumber?->id, $callerId, $number, $contactField, $reference, CallChannel::WebRtc, null, CallStatus::Initiated, null);

            return ['call' => $call, 'dial' => ['number' => '+'.$number, 'reference' => $reference]];
        }

        $agentPhone = $this->phones->normalize($agent->registered_phone);
        if (! $agentPhone) {
            throw TelephonyException::notConfigured('No registered phone is set up for your calling account. Please contact your administrator.');
        }

        try {
            $result = $provider->initiateOutboundCall(new OutboundCallRequest(
                agentNumber: $agentPhone,
                customerNumber: $number,
                callerId: $callerId,
                reference: $reference,
                record: $integration->recording_enabled,
                statusCallbackUrl: $provider->callbackUrl('status'),
            ));
        } catch (TelephonyException $e) {
            $this->recordProviderError($integration, $e);
            throw $e;
        }

        $call = $this->persist($actor, $lead, $integration, $callerNumber?->id, $callerId, $number, $contactField, $reference, CallChannel::Pstn, $result->providerCallId, $result->status, $result->providerStatus, $agentPhone);

        return ['call' => $call, 'dial' => null];
    }

    /**
     * Modes the actor could use right now (for the Call button). Never throws.
     *
     * @return array{enabled: bool, modes: array<string>, default: ?string, reason: ?string}
     */
    public function availability(User $actor): array
    {
        $off = fn (string $reason) => ['enabled' => false, 'modes' => [], 'default' => null, 'reason' => $reason];

        if (! $actor->is_active || ! $actor->hasPermission(Permissions::CALL_MAKE)) {
            return $off('You are not allowed to make calls.');
        }

        $integration = $this->telephony->activeIntegration();
        if (! $integration) {
            return $off('Calling is not enabled.');
        }

        try {
            $provider = $this->telephony->provider();
        } catch (\RuntimeException) {
            return $off('Calling is not configured correctly.');
        }

        if (! $provider->isConfigured()) {
            return $off('Calling is not configured yet.');
        }

        $agent = $this->directory->agentFor($integration, $actor);
        if (! $agent) {
            return $off('Your calling account is not set up yet.');
        }

        $modes = [];
        if ($integration->browser_calling_enabled && $provider->supportsWebRtc() && (filled($agent->provider_user_id) || $this->telephony->isFake())) {
            $modes[] = TelephonyCallingMode::WebRtc->value;
        }
        if ($integration->pstn_calling_enabled && filled($agent->registered_phone)) {
            $modes[] = TelephonyCallingMode::Pstn->value;
        }

        if ($modes === []) {
            return $off('No calling mode is available for your account.');
        }

        $preferred = $agent->calling_mode?->value;
        $default = in_array($preferred, $modes, true) ? $preferred : $modes[0];

        return ['enabled' => true, 'modes' => $modes, 'default' => $default, 'reason' => null];
    }

    /** @return array{0: string, 1: string} normalised number + contact field */
    private function resolveDestination(User $actor, ?Lead $lead, array $input): array
    {
        $manual = isset($input['number']) && trim((string) $input['number']) !== '';

        if ($manual) {
            if (! $actor->hasPermission(Permissions::CALL_MANUAL_DIAL)) {
                throw ValidationException::withMessages(['number' => 'You are not allowed to dial numbers manually.']);
            }

            $normalized = $this->phones->normalize((string) $input['number']);
            if (! $normalized || strlen($normalized) < 8 || strlen($normalized) > 15) {
                throw ValidationException::withMessages(['number' => 'Enter a valid phone number.']);
            }

            return [$normalized, 'manual'];
        }

        if ($lead === null) {
            throw ValidationException::withMessages(['number' => 'Choose a lead to call.']);
        }

        $field = (string) ($input['contact_field'] ?? 'phone');
        if (! in_array($field, self::CONTACT_FIELDS, true)) {
            throw ValidationException::withMessages(['contact_field' => 'Choose which of the lead\'s numbers to call.']);
        }

        $normalized = $this->phones->normalize($lead->{$field});
        if (! $normalized) {
            throw ValidationException::withMessages(['contact_field' => 'This lead has no valid number for the selected option.']);
        }

        return [$normalized, $field];
    }

    private function resolveMode(TelephonyIntegration $integration, TelephonyUser $agent, ?string $requested): TelephonyCallingMode
    {
        $provider = $this->telephony->provider();
        $webRtcReady = $integration->browser_calling_enabled && $provider->supportsWebRtc();
        $pstnReady = $integration->pstn_calling_enabled && filled($agent->registered_phone);

        $mode = TelephonyCallingMode::tryFrom((string) $requested) ?? $agent->calling_mode ?? TelephonyCallingMode::Pstn;

        if ($mode === TelephonyCallingMode::WebRtc && $webRtcReady) {
            return $mode;
        }
        if ($mode === TelephonyCallingMode::Pstn && $pstnReady) {
            return $mode;
        }
        if ($requested === null) {
            if ($pstnReady) {
                return TelephonyCallingMode::Pstn;
            }
            if ($webRtcReady) {
                return TelephonyCallingMode::WebRtc;
            }
        }

        throw TelephonyException::notConfigured($mode === TelephonyCallingMode::WebRtc
            ? 'Browser calling is not available for your account.'
            : 'Phone (click-to-call) calling is not available for your account.');
    }

    private function persist(
        User $actor, ?Lead $lead, TelephonyIntegration $integration, ?int $numberId, ?string $callerId,
        string $customer, string $contactField, string $reference, CallChannel $channel,
        ?string $providerCallId, CallStatus $status, ?string $providerStatus, ?string $agentPhone = null,
    ): Call {
        return DB::transaction(function () use ($actor, $lead, $integration, $numberId, $callerId, $customer, $contactField, $reference, $channel, $providerCallId, $status, $providerStatus, $agentPhone) {
            $call = new Call;
            $call->forceFill([
                'call_number' => $this->numbers->next(),
                'client_reference' => $reference,
                'lead_id' => $lead?->id,
                'agent_user_id' => $actor->id,
                'integration_id' => $integration->id,
                'telephony_number_id' => $numberId,
                'provider' => $this->telephony->provider()->name(),
                'provider_call_id' => $providerCallId,
                'provider_status' => $providerStatus,
                'direction' => CallDirection::Outbound,
                'channel' => $channel,
                'contact_field' => $contactField,
                'from_number' => $agentPhone ? '+'.$agentPhone : null,
                'from_number_normalized' => $agentPhone,
                'to_number' => '+'.$customer,
                'to_number_normalized' => $customer,
                'customer_number_normalized' => $customer,
                'virtual_number' => $callerId,
                'status' => $status,
                'started_at' => now(),
                'last_event_at' => now(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            if ($lead) {
                $this->activities->record($lead, ActivityService::CALL_STARTED, "{$actor->name} started an outbound call.", ['call_id' => $call->id], $actor->id);
            }

            $this->audit->log(AuditAction::CallInitiated, 'calls', $call, "Outbound call {$call->call_number} started".($lead ? " to {$lead->lead_number}" : ' (manual dial)'), null, array_filter([
                'call_number' => $call->call_number,
                'lead_number' => $lead?->lead_number,
                'channel' => $channel->value,
                'contact_field' => $contactField,
                'provider_call_id' => $providerCallId,
            ]), $actor->id);

            return $call;
        });
    }

    private function recordProviderError(TelephonyIntegration $integration, TelephonyException $e): void
    {
        Log::warning('Outbound call request failed', ['category' => $e->category, 'detail' => $e->detail]);

        $integration->forceFill([
            'last_error' => mb_substr($e->detail ?? $e->getMessage(), 0, 500),
            'last_error_at' => now(),
        ])->save();
    }
}
