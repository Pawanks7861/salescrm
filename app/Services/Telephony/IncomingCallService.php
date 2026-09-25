<?php

namespace App\Services\Telephony;

use App\Enums\CallDirection;
use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\PhoneNormalizer;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Incoming-call screen pop. Only leads the receiving user may see are ever
 * returned. When the number belongs only to leads the user cannot access,
 * the response is generic ("Lead information unavailable.") — no name,
 * status, owner, campaign or notes.
 */
class IncomingCallService
{
    public const MATCHED = 'matched';

    public const MULTIPLE = 'multiple';

    public const RESTRICTED = 'restricted';

    public const UNKNOWN = 'unknown';

    public function __construct(
        private readonly TelephonyManager $telephony,
        private readonly CallVisibility $calls,
        private readonly PhoneNormalizer $phones,
    ) {}

    /** @throws AuthorizationException */
    public function identify(User $user, ?string $providerCallId, ?string $number): array
    {
        if (! $user->hasPermission(Permissions::CALL_RECEIVE)) {
            throw new AuthorizationException('You are not allowed to receive calls.');
        }

        $call = null;
        if ($providerCallId) {
            $call = Call::query()
                ->where('provider', $this->telephony->provider()->name())
                ->where('provider_call_id', $providerCallId)
                ->where('direction', CallDirection::Inbound->value)
                ->first();
        }

        $normalized = $call?->customer_number_normalized ?? $this->phones->normalize($number);
        $visibleCall = $call && $this->calls->canView($user, $call) ? $call : null;

        $base = [
            'number' => $normalized ? '+'.$normalized : null,
            'call_id' => $visibleCall?->id,
            'leads' => [],
            'can_create_lead' => false,
            'message' => null,
        ];

        if (! $normalized) {
            return $base + ['state' => self::UNKNOWN];
        }

        $matching = fn () => Lead::query()->where(fn ($q) => $q
            ->where('leads.normalized_phone', $normalized)
            ->orWhere('leads.normalized_alternate_phone', $normalized));

        $visible = $matching()->visibleTo($user)
            ->with(['status:id,name,color', 'assignee:id,name'])
            ->orderByDesc('leads.updated_at')
            ->limit(5)
            ->get(['leads.id', 'leads.lead_number', 'leads.full_name', 'leads.status_id', 'leads.assigned_to']);

        if ($visible->isNotEmpty()) {
            return array_merge($base, [
                'state' => $visible->count() === 1 ? self::MATCHED : self::MULTIPLE,
                'leads' => $visible->map(fn (Lead $lead) => [
                    'id' => $lead->id,
                    'lead_number' => $lead->lead_number,
                    'name' => $lead->full_name,
                    'status' => $lead->status ? ['name' => $lead->status->name, 'color' => $lead->status->color] : null,
                    'assignee' => $lead->assignee?->name,
                    'url' => route('leads.show', $lead->id, false),
                ])->values()->all(),
            ]);
        }

        if ($matching()->exists()) {
            return array_merge($base, ['state' => self::RESTRICTED, 'message' => 'Lead information unavailable.']);
        }

        return array_merge($base, [
            'state' => self::UNKNOWN,
            'can_create_lead' => $user->hasPermission(Permissions::LEAD_CREATE),
        ]);
    }
}
