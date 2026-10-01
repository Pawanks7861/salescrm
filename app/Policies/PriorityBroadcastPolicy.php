<?php

namespace App\Policies;

use App\Models\PriorityBroadcast;
use App\Models\User;
use App\Support\Permissions;

class PriorityBroadcastPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::PRIORITY_BROADCAST_VIEW_HISTORY);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::PRIORITY_BROADCAST_SEND);
    }

    /** Recipients, the sender, and history viewers. */
    public function view(User $user, PriorityBroadcast $broadcast): bool
    {
        return $broadcast->sent_by === $user->id
            || $this->viewAny($user)
            || $broadcast->recipients()->where('user_id', $user->id)->exists();
    }

    /** Recipient statistics and the per-user list. */
    public function viewStats(User $user, PriorityBroadcast $broadcast): bool
    {
        return $broadcast->sent_by === $user->id || $this->viewAny($user);
    }
}
