<?php

namespace App\Policies;

use App\Models\Batch;
use App\Models\User;
use App\Support\Permissions as P;

/**
 * Batch abilities only gate the batch itself. Which leads a user sees, adds
 * or removes is always decided by LeadVisibility (see BatchService), never by
 * batch membership or batches.created_by. Ability names contain no dot, so
 * Gate::before never short-circuits them.
 */
class BatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(P::BATCH_VIEW);
    }

    public function view(User $user, Batch $batch): bool
    {
        return ! $batch->trashed() && $user->hasPermission(P::BATCH_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(P::BATCH_CREATE);
    }

    public function update(User $user, Batch $batch): bool
    {
        return ! $batch->trashed() && $user->hasPermission(P::BATCH_EDIT);
    }

    public function archive(User $user, Batch $batch): bool
    {
        return ! $batch->trashed() && ! $batch->isArchived() && $user->hasPermission(P::BATCH_DELETE);
    }

    public function restore(User $user, Batch $batch): bool
    {
        return ! $batch->trashed() && $batch->isArchived() && $user->hasPermission(P::BATCH_DELETE);
    }

    public function delete(User $user, Batch $batch): bool
    {
        return ! $batch->trashed() && $user->hasPermission(P::BATCH_DELETE);
    }

    public function addLeads(User $user, Batch $batch): bool
    {
        return ! $batch->trashed() && ! $batch->isArchived() && $this->managesLeads($user);
    }

    public function removeLeads(User $user, Batch $batch): bool
    {
        return ! $batch->trashed() && $this->managesLeads($user);
    }

    /** Trainer assignment needs no lead permission: it never exposes leads. */
    public function addTrainers(User $user, Batch $batch): bool
    {
        return ! $batch->trashed() && ! $batch->isArchived() && $user->hasPermission(P::BATCH_MANAGE_TRAINERS);
    }

    public function removeTrainers(User $user, Batch $batch): bool
    {
        return ! $batch->trashed() && $user->hasPermission(P::BATCH_MANAGE_TRAINERS);
    }

    private function managesLeads(User $user): bool
    {
        return $user->hasPermission(P::BATCH_MANAGE_LEADS)
            && $user->hasAnyPermission(P::LEAD_VIEW, P::LEAD_VIEW_ALL);
    }
}
