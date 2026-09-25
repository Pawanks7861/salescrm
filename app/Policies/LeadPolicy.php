<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadVisibility;
use App\Support\Permissions as P;

/**
 * Every record-level ability requires the lead to be inside the user's
 * visibility scope in addition to the functional permission. Ability names
 * contain no dot, so Gate::before never short-circuits them.
 */
class LeadPolicy
{
    public function __construct(private readonly LeadVisibility $visibility) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(P::LEAD_VIEW, P::LEAD_VIEW_ALL);
    }

    public function view(User $user, Lead $lead): bool
    {
        if ($lead->trashed() && ! $user->hasAnyPermission(P::LEAD_DELETE, P::LEAD_RESTORE)) {
            return false;
        }

        return $this->visibility->canView($user, $lead);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(P::LEAD_CREATE);
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->active($user, $lead) && $user->hasPermission(P::LEAD_EDIT);
    }

    public function changeStatus(User $user, Lead $lead): bool
    {
        return $this->active($user, $lead) && $user->hasPermission(P::LEAD_CHANGE_STATUS);
    }

    public function assign(User $user, Lead $lead): bool
    {
        $required = $lead->assigned_to === null ? P::LEAD_ASSIGN : P::LEAD_REASSIGN;

        return $this->active($user, $lead) && $user->hasPermission($required);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $this->active($user, $lead) && $user->hasPermission(P::LEAD_DELETE);
    }

    public function restore(User $user, Lead $lead): bool
    {
        return $lead->trashed() && $user->hasPermission(P::LEAD_RESTORE) && $this->visibility->canView($user, $lead);
    }

    public function addNote(User $user, Lead $lead): bool
    {
        return $this->active($user, $lead);
    }

    public function viewAttachments(User $user, Lead $lead): bool
    {
        return $user->hasPermission(P::FILE_VIEW) && $this->view($user, $lead);
    }

    public function uploadAttachment(User $user, Lead $lead): bool
    {
        return $this->active($user, $lead) && $user->hasPermission(P::FILE_UPLOAD);
    }

    public function downloadAttachment(User $user, Lead $lead): bool
    {
        return $user->hasPermission(P::FILE_DOWNLOAD) && $this->view($user, $lead);
    }

    public function deleteAttachment(User $user, Lead $lead, Attachment $attachment): bool
    {
        return $this->active($user, $lead)
            && ((int) $attachment->uploaded_by === $user->id || $user->hasPermission(P::LEAD_DELETE));
    }

    private function active(User $user, Lead $lead): bool
    {
        return ! $lead->trashed() && $this->visibility->canView($user, $lead);
    }
}
