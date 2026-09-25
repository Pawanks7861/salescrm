<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for "which leads may this user see".
 *
 *  - lead.view_all → every lead, including unassigned ones
 *  - lead.view     → only leads where leads.assigned_to = the user
 *  - otherwise     → nothing
 *
 * There is no team, manager or department tier: teams never grant access and
 * unassigned leads are visible to view_all holders only.
 *
 * `apply()` (SQL) and `canView()` (in-memory) implement the same rules and must
 * stay in sync; every list, search, pipeline, duplicate lookup and policy uses
 * this class.
 */
class LeadVisibility
{
    public const ALL = 'all';

    public const OWN = 'own';

    public const NONE = 'none';

    public function tier(User $user): string
    {
        return match (true) {
            $user->hasPermission(Permissions::LEAD_VIEW_ALL) => self::ALL,
            $user->hasPermission(Permissions::LEAD_VIEW) => self::OWN,
            default => self::NONE,
        };
    }

    public function apply(Builder $query, User $user): Builder
    {
        $table = $query->getModel()->getTable();

        return match ($this->tier($user)) {
            self::ALL => $query,
            self::OWN => $query->where("{$table}.assigned_to", $user->id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function canView(User $user, Lead $lead): bool
    {
        return match ($this->tier($user)) {
            self::ALL => true,
            self::OWN => $lead->assigned_to !== null && (int) $lead->assigned_to === $user->id,
            default => false,
        };
    }

    /** Whether the user owns the lead (the OWN rule), regardless of view_all. */
    public function owns(User $user, ?Lead $lead): bool
    {
        return $lead !== null && $lead->assigned_to !== null && (int) $lead->assigned_to === $user->id;
    }

    /**
     * Users this user may hand leads to. `null` means any active user.
     * Holders of lead.assign / lead.reassign may hand a lead they can see to
     * any active user (and then lose access if they are not the new owner);
     * everyone else may only take ownership themselves.
     *
     * @return array<int>|null
     */
    public function assignableUserIds(User $user): ?array
    {
        return $user->hasAnyPermission(Permissions::LEAD_ASSIGN, Permissions::LEAD_REASSIGN) ? null : [$user->id];
    }
}
