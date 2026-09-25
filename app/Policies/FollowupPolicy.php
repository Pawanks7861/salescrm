<?php

namespace App\Policies;

use App\Models\Followup;
use App\Models\Lead;
use App\Models\User;
use App\Services\Followups\FollowupVisibility;
use App\Support\Permissions as P;

/**
 * Every record ability requires FollowupVisibility (which itself requires the
 * parent lead to be visible) in addition to the functional permission. Only
 * pending follow-ups can be changed: completed, cancelled and rescheduled
 * records are history. Ability names contain no dot, so Gate::before never
 * short-circuits them.
 */
class FollowupPolicy
{
    public function __construct(private readonly FollowupVisibility $visibility) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(P::FOLLOWUP_VIEW, P::FOLLOWUP_VIEW_ALL);
    }

    public function view(User $user, Followup $followup): bool
    {
        if ($followup->trashed() && ! $user->hasPermission(P::FOLLOWUP_DELETE)) {
            return false;
        }

        return $this->visibility->canView($user, $followup);
    }

    /** Create on a specific lead the user can access. */
    public function create(User $user, ?Lead $lead = null): bool
    {
        if (! $user->hasPermission(P::FOLLOWUP_CREATE)) {
            return false;
        }

        return $lead === null || $this->visibility->canSeeLead($user, $lead);
    }

    public function update(User $user, Followup $followup): bool
    {
        return $user->hasPermission(P::FOLLOWUP_EDIT) && $this->open($user, $followup);
    }

    public function reschedule(User $user, Followup $followup): bool
    {
        return $user->hasPermission(P::FOLLOWUP_EDIT) && $this->open($user, $followup);
    }

    public function complete(User $user, Followup $followup): bool
    {
        return $user->hasPermission(P::FOLLOWUP_COMPLETE) && $this->open($user, $followup);
    }

    public function cancel(User $user, Followup $followup): bool
    {
        return $user->hasPermission(P::FOLLOWUP_CANCEL) && $this->open($user, $followup);
    }

    public function delete(User $user, Followup $followup): bool
    {
        return ! $followup->trashed() && $user->hasPermission(P::FOLLOWUP_DELETE) && $this->visibility->canView($user, $followup);
    }

    public function restore(User $user, Followup $followup): bool
    {
        return $followup->trashed() && $user->hasPermission(P::FOLLOWUP_DELETE) && $this->visibility->canView($user, $followup);
    }

    private function open(User $user, Followup $followup): bool
    {
        return ! $followup->trashed() && $followup->isPending() && $this->visibility->canView($user, $followup);
    }
}
