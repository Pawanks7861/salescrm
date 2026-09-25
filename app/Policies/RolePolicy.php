<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;

class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permissions::ROLE_VIEW);
    }

    public function view(User $actor, Role $role): bool
    {
        return $actor->hasPermission(Permissions::ROLE_VIEW);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permissions::ROLE_MANAGE);
    }

    public function update(User $actor, Role $role): bool
    {
        return $actor->hasPermission(Permissions::ROLE_MANAGE) && ! $role->isSuperAdmin();
    }

    public function delete(User $actor, Role $role): bool
    {
        return $actor->hasPermission(Permissions::ROLE_MANAGE) && ! $role->is_system;
    }
}
