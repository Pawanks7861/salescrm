<?php

namespace App\Policies;

use App\Enums\MeetingStatus;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\User;
use App\Services\Meetings\MeetingVisibility;
use App\Support\Permissions as P;

/**
 * Every record ability requires MeetingVisibility (which itself requires the
 * linked lead to be visible). Changing a meeting additionally requires
 * canManage (host, the person who scheduled it, or view_all). Completed, cancelled,
 * no-show and rescheduled meetings are history and read-only. Ability names
 * contain no dot, so Gate::before never short-circuits them.
 */
class MeetingPolicy
{
    public function __construct(private readonly MeetingVisibility $visibility) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(P::MEETING_VIEW, P::MEETING_VIEW_ALL);
    }

    public function view(User $user, Meeting $meeting): bool
    {
        if ($meeting->trashed() && ! $user->hasPermission(P::MEETING_DELETE)) {
            return false;
        }

        return $this->visibility->canView($user, $meeting);
    }

    /** Create on a specific lead the user can access (or generally, when no lead given). */
    public function create(User $user, ?Lead $lead = null): bool
    {
        if (! $user->hasPermission(P::MEETING_CREATE)) {
            return false;
        }

        return $lead === null || $this->visibility->canSeeLead($user, $lead);
    }

    public function createWithoutLead(User $user): bool
    {
        return $user->hasPermission(P::MEETING_CREATE) && $user->hasPermission(P::MEETING_CREATE_WITHOUT_LEAD);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(P::MEETING_EDIT) && $this->upcoming($user, $meeting);
    }

    public function reschedule(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(P::MEETING_EDIT) && $this->upcoming($user, $meeting);
    }

    public function manageParticipants(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(P::MEETING_EDIT) && $this->upcoming($user, $meeting);
    }

    public function confirm(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(P::MEETING_EDIT) && $meeting->status === MeetingStatus::Scheduled && $this->upcoming($user, $meeting);
    }

    public function start(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(P::MEETING_COMPLETE) && $this->upcoming($user, $meeting);
    }

    public function complete(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(P::MEETING_COMPLETE) && $this->open($user, $meeting);
    }

    public function noShow(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(P::MEETING_COMPLETE) && $this->open($user, $meeting);
    }

    public function cancel(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(P::MEETING_CANCEL) && $this->open($user, $meeting);
    }

    /** An internal participant may confirm / decline their own attendance. */
    public function respond(User $user, Meeting $meeting): bool
    {
        return ! $meeting->trashed() && $meeting->isUpcoming() && $this->visibility->canView($user, $meeting);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return ! $meeting->trashed() && $user->hasPermission(P::MEETING_DELETE) && $this->visibility->canView($user, $meeting);
    }

    public function restore(User $user, Meeting $meeting): bool
    {
        return $meeting->trashed() && $user->hasPermission(P::MEETING_DELETE) && $this->visibility->canView($user, $meeting);
    }

    /** Admin notes are allowed on any visible meeting, including completed ones. */
    public function addNote(User $user, Meeting $meeting): bool
    {
        return ! $meeting->trashed() && $user->isAdmin() && $this->visibility->canView($user, $meeting);
    }

    private function upcoming(User $user, Meeting $meeting): bool
    {
        return ! $meeting->trashed() && $meeting->isUpcoming() && $this->visibility->canManage($user, $meeting);
    }

    private function open(User $user, Meeting $meeting): bool
    {
        return ! $meeting->trashed() && $meeting->isOpen() && $this->visibility->canManage($user, $meeting);
    }
}
