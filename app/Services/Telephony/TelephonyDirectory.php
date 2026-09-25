<?php

namespace App\Services\Telephony;

use App\Models\TelephonyIntegration;
use App\Models\TelephonyNumber;
use App\Models\TelephonyUser;
use App\Models\User;
use App\Services\Leads\PhoneNormalizer;

/** Look-ups of agent mappings and company numbers for the active integration. */
class TelephonyDirectory
{
    public function __construct(private readonly PhoneNormalizer $phones) {}

    public function agentFor(TelephonyIntegration $integration, User $user): ?TelephonyUser
    {
        return TelephonyUser::query()
            ->where('integration_id', $integration->id)
            ->where('user_id', $user->id)
            ->where('is_enabled', true)
            ->first();
    }

    /** Resolves the CRM agent from the leg the provider dialled (phone number or SIP identity). */
    public function agentByDialledLeg(TelephonyIntegration $integration, ?string $leg): ?TelephonyUser
    {
        if ($leg === null || trim($leg) === '') {
            return null;
        }

        $query = TelephonyUser::query()->where('integration_id', $integration->id)->with('user');

        if (preg_match('/^sip:([^@;]+)/i', trim($leg), $m)) {
            $identity = $m[1];

            return (clone $query)->where(fn ($q) => $q->where('provider_sip_username', $identity)->orWhere('provider_user_id', $identity))->first();
        }

        $normalized = $this->phones->normalize($leg);

        return $normalized ? $query->where('registered_phone_normalized', $normalized)->first() : null;
    }

    public function numberFor(TelephonyIntegration $integration, ?string $raw): ?TelephonyNumber
    {
        $normalized = $this->phones->normalize($raw);

        return $normalized
            ? TelephonyNumber::query()->where('integration_id', $integration->id)->where('normalized_number', $normalized)->first()
            : null;
    }

    /** Caller ID: the default outbound number (legacy per-team numbers are ignored). */
    public function outboundNumber(TelephonyIntegration $integration, User $agent): ?TelephonyNumber
    {
        return TelephonyNumber::query()
            ->where('integration_id', $integration->id)
            ->where('is_active', true)
            ->where('supports_outbound', true)
            ->where('is_default', true)
            ->first();
    }
}
