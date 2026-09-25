<?php

namespace App\Services\Telephony;

use App\Models\Call;
use App\Models\User;
use App\Services\Leads\LeadVisibility;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for "which calls may this user see".
 *
 * A call is visible only when BOTH hold:
 *   1. its own tier allows it
 *        call.view_all → any call
 *        call.view     → calls where they are the agent, or on a lead they own
 *   2. when linked to a lead, that lead is visible through LeadVisibility and
 *      not archived. Lead visibility is authoritative: having made a call
 *      never grants access to a lead that is now someone else's.
 *
 * Calls without a lead (unknown callers, manual dials) are governed by rule 1.
 * There is no team tier. `apply()` (SQL) and `canView()` (in-memory) must
 * stay in sync.
 */
class CallVisibility
{
    public const ALL = 'all';

    public const OWN = 'own';

    public const NONE = 'none';

    public function __construct(private readonly LeadVisibility $leads) {}

    public function tier(User $user): string
    {
        return match (true) {
            $user->hasPermission(Permissions::CALL_VIEW_ALL) => self::ALL,
            $user->hasPermission(Permissions::CALL_VIEW) => self::OWN,
            default => self::NONE,
        };
    }

    /** `$includeArchivedLeads` is for historical reports only; operational screens never pass it. */
    public function apply(Builder $query, User $user, bool $includeArchivedLeads = false): Builder
    {
        $table = $query->getModel()->getTable();

        match ($this->tier($user)) {
            self::ALL => null,
            self::OWN => $query->where(fn (Builder $q) => $q
                ->where("{$table}.agent_user_id", $user->id)
                ->orWhereHas('lead', fn (Builder $lead) => $lead->withTrashed()->where('leads.assigned_to', $user->id))),
            default => $query->whereRaw('1 = 0'),
        };

        return $query->where(fn (Builder $q) => $q
            ->whereNull("{$table}.lead_id")
            ->orWhereHas('lead', fn (Builder $lead) => $this->leads->apply($includeArchivedLeads ? $lead->withTrashed() : $lead->whereNull('leads.deleted_at'), $user)));
    }

    public function canView(User $user, Call $call): bool
    {
        $allowed = match ($this->tier($user)) {
            self::ALL => true,
            self::OWN => ($call->agent_user_id !== null && (int) $call->agent_user_id === $user->id)
                || ($call->lead_id !== null && $this->leads->owns($user, $call->lead)),
            default => false,
        };

        return $allowed && $this->leadVisible($user, $call);
    }

    /** Disposition / notes / next action: anyone who can see the call. */
    public function canManage(User $user, Call $call): bool
    {
        return $this->canView($user, $call);
    }

    private function leadVisible(User $user, Call $call): bool
    {
        if ($call->lead_id === null) {
            return true;
        }

        $lead = $call->lead;

        return $lead !== null && ! $lead->trashed() && $this->leads->canView($user, $lead);
    }
}
