<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoleService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PermissionRegistrar $permissions,
    ) {}

    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
            ]);

            $this->audit->log(AuditAction::RoleCreated, 'roles', $role, "Role {$role->name} created", null, $role->only('name', 'slug', 'description'));

            return $role;
        });
    }

    public function update(Role $role, array $data): Role
    {
        $this->guardSystemRole($role);

        $role->fill(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        [$old, $new] = $this->audit->dirtyDiff($role);
        $role->save();

        if ($new !== []) {
            $this->audit->log(AuditAction::RoleUpdated, 'roles', $role, "Role {$role->name} updated", $old, $new);
        }

        return $role;
    }

    /** @param  array<string>  $permissionNames */
    public function syncPermissions(Role $role, array $permissionNames): void
    {
        if ($role->isSuperAdmin()) {
            throw ValidationException::withMessages(['permissions' => 'Super Admin permissions cannot be changed.']);
        }

        $before = $role->permissions()->orderBy('name')->pluck('name')->all();

        DB::transaction(function () use ($role, $permissionNames) {
            $role->permissions()->sync(Permission::whereIn('name', $permissionNames)->pluck('id'));
        });

        $after = $role->permissions()->orderBy('name')->pluck('name')->all();
        $this->permissions->flushAll();

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added || $removed) {
            $this->audit->log(
                AuditAction::PermissionChanged,
                'roles',
                $role,
                "Permissions of role {$role->name} changed (+".count($added).' / -'.count($removed).')',
                ['removed' => $removed],
                ['added' => $added],
            );
        }
    }

    public function delete(Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => 'System roles cannot be deleted.']);
        }

        if ($role->users()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['role' => 'Reassign users of this role before deleting it.']);
        }

        DB::transaction(function () use ($role) {
            $snapshot = [...$role->only('name', 'slug'), 'permissions' => $role->permissions()->pluck('name')->all()];
            $role->delete();
            $this->audit->log(AuditAction::RoleDeleted, 'roles', $role, "Role {$role->name} deleted", $snapshot, null);
        });

        $this->permissions->flushAll();
    }

    private function guardSystemRole(Role $role): void
    {
        if ($role->isSuperAdmin()) {
            throw ValidationException::withMessages(['role' => 'The Super Admin role cannot be modified.']);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name, '_');
        $slug = $base;
        $i = 2;

        while (Role::where('slug', $slug)->exists()) {
            $slug = "{$base}_{$i}";
            $i++;
        }

        return $slug;
    }
}
