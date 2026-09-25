<?php

namespace App\Policies;

use App\Models\LeadNote;
use App\Models\User;
use App\Services\Leads\LeadNoteService;
use App\Services\Leads\LeadVisibility;
use App\Support\Permissions as P;

class LeadNotePolicy
{
    public function __construct(
        private readonly LeadVisibility $visibility,
        private readonly LeadNoteService $notes,
    ) {}

    public function view(User $user, LeadNote $note): bool
    {
        return $this->leadAccessible($user, $note) && $this->notes->canRead($user, $note);
    }

    public function update(User $user, LeadNote $note): bool
    {
        return $this->view($user, $note)
            && ((int) $note->created_by === $user->id || $user->hasPermission(P::NOTE_EDIT_ANY));
    }

    public function delete(User $user, LeadNote $note): bool
    {
        return $this->view($user, $note)
            && ((int) $note->created_by === $user->id || $user->hasPermission(P::NOTE_DELETE));
    }

    private function leadAccessible(User $user, LeadNote $note): bool
    {
        $lead = $note->lead;

        return $lead !== null && ! $lead->trashed() && $this->visibility->canView($user, $lead);
    }
}
