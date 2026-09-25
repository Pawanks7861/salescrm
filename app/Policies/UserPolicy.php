<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permissions::USER_VIEW);
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->hasPermission(Permissions::USER_VIEW);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permissions::USER_CREATE);
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->hasPermission(Permissions::USER_EDIT) && $this->canTouch($actor, $user);
    }

    public function toggleActive(User $actor, User $user): bool
    {
        return $actor->hasPermission(Permissions::USER_DISABLE)
            && ! $actor->is($user)
            && $this->canTouch($actor, $user);
    }

    public function resetPassword(User $actor, User $user): bool
    {
        return $actor->hasPermission(Permissions::USER_RESET_PASSWORD) && $this->canTouch($actor, $user);
    }

    public function managePermissions(User $actor, User $user): bool
    {
        return $actor->hasPermission(Permissions::ROLE_MANAGE)
            && ! $actor->is($user)
            && ! $user->isSuperAdmin();
    }

    /** Non super admins can never modify a Super Admin account. */
    private function canTouch(User $actor, User $user): bool
    {
        return ! $user->isSuperAdmin() || $actor->isSuperAdmin();
    }
}
