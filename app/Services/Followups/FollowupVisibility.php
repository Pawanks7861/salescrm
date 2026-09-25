<?php

namespace App\Services\Followups;

use App\Models\Followup;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadVisibility;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for "which follow-ups may this user see".
 *
 * A follow-up is visible only when BOTH hold:
 *   1. its own tier allows it
 *        followup.view_all → any follow-up
 *        followup.view     → assigned to themselves, or on a lead they own
 *   2. the parent lead is visible through LeadVisibility (and not archived)
 *
 * Rule 2 means a follow-up can never be used to reach a lead the user could
 * not otherwise open. There is no team tier. `apply()` (SQL) and `canView()`
 * (in-memory) must match.
 */
class FollowupVisibility
{
    public const ALL = 'all';

    public const OWN = 'own';

    public const NONE = 'none';

    public function __construct(private readonly LeadVisibility $leads) {}

    public function tier(User $user): string
    {
        return match (true) {
            $user->hasPermission(Permissions::FOLLOWUP_VIEW_ALL) => self::ALL,
            $user->hasPermission(Permissions::FOLLOWUP_VIEW) => self::OWN,
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
                ->where("{$table}.assigned_to", $user->id)
                ->orWhereHas('lead', fn (Builder $lead) => $lead->withTrashed()->where('leads.assigned_to', $user->id))),
            default => $query->whereRaw('1 = 0'),
        };

        return $query->whereHas('lead', fn (Builder $lead) => $this->leads->apply($includeArchivedLeads ? $lead->withTrashed() : $lead, $user));
    }

    public function canView(User $user, Followup $followup): bool
    {
        $lead = $followup->lead;

        $allowed = match ($this->tier($user)) {
            self::ALL => true,
            self::OWN => ($followup->assigned_to !== null && (int) $followup->assigned_to === $user->id) || $this->leads->owns($user, $lead),
            default => false,
        };

        return $allowed && $lead !== null && ! $lead->trashed() && $this->leads->canView($user, $lead);
    }

    /** Whether the user could see follow-ups on this lead at all (used for assignee checks). */
    public function canSeeLead(User $user, Lead $lead): bool
    {
        return $this->tier($user) !== self::NONE && ! $lead->trashed() && $this->leads->canView($user, $lead);
    }

    /**
     * Users this user may assign follow-ups to. `null` means any active user.
     * Without followup.assign a user may only schedule for themselves. The
     * assignee must still be able to see the lead (checked by FollowupService),
     * so this never widens lead access.
     *
     * @return array<int>|null
     */
    public function assignableUserIds(User $user): ?array
    {
        return $user->hasPermission(Permissions::FOLLOWUP_ASSIGN) && $this->tier($user) !== self::NONE ? null : [$user->id];
    }
}
