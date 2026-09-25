<?php

namespace App\Policies;

use App\Models\Call;
use App\Models\User;
use App\Services\Telephony\CallVisibility;
use App\Support\Permissions as P;

/**
 * Every record ability requires CallVisibility (which itself requires the
 * linked lead to be visible). Nobody deletes calls; provider identifiers,
 * timestamps, durations, direction, agent and recording metadata are never
 * editable. Ability names contain no dot, so Gate::before never
 * short-circuits them.
 */
class CallPolicy
{
    public function __construct(private readonly CallVisibility $visibility) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(P::CALL_VIEW, P::CALL_VIEW_ALL);
    }

    public function view(User $user, Call $call): bool
    {
        return $this->visibility->canView($user, $call);
    }

    public function dispose(User $user, Call $call): bool
    {
        return $user->hasPermission(P::CALL_ADD_DISPOSITION) && $this->visibility->canManage($user, $call);
    }

    public function editNotes(User $user, Call $call): bool
    {
        return $user->hasPermission(P::CALL_EDIT_NOTES) && $this->visibility->canManage($user, $call);
    }

    public function listen(User $user, Call $call): bool
    {
        return $user->hasPermission(P::CALL_RECORDING_LISTEN) && $this->visibility->canView($user, $call);
    }

    public function download(User $user, Call $call): bool
    {
        return $user->hasPermission(P::CALL_RECORDING_DOWNLOAD) && $this->visibility->canView($user, $call);
    }

    /** Raw provider event timeline (operational detail). */
    public function viewEvents(User $user, Call $call): bool
    {
        return $user->hasAnyPermission(P::CALL_MONITOR, P::CALL_CONFIGURE) && $this->visibility->canView($user, $call);
    }

    public function delete(User $user, Call $call): bool
    {
        return false;
    }

    public function update(User $user, Call $call): bool
    {
        return false;
    }
}
