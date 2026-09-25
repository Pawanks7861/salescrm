<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    /** Attributes included in audit old/new snapshots. */
    private const AUDITED = [
        'name', 'employee_code', 'email', 'phone', 'designation',
        'role_id', 'is_active',
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly PermissionRegistrar $permissions,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, role_id: int, is_active?: bool}  $data
     */
    public function create(array $data, User $actor): User
    {
        $this->guardRoleAssignment((int) $data['role_id'], $actor);

        return DB::transaction(function () use ($data) {
            $user = new User;
            $user->fill($data);
            $this->applyOrganisation($user, $data);
            $user->is_active = $data['is_active'] ?? true;
            $user->email_verified_at = now();
            $user->save();

            $this->audit->log(
                AuditAction::UserCreated,
                'users',
                $user,
                "User {$user->name} created",
                null,
                $user->only(self::AUDITED),
            );

            return $user;
        });
    }

    public function update(User $user, array $data, User $actor): User
    {
        if (isset($data['role_id']) && (int) $data['role_id'] !== $user->role_id) {
            if ($user->is($actor)) {
                throw ValidationException::withMessages(['role_id' => 'You cannot change your own role.']);
            }
            $this->guardRoleAssignment((int) $data['role_id'], $actor);
        }

        return DB::transaction(function () use ($user, $data) {
            $user->fill(collect($data)->except('password')->all());
            $this->applyOrganisation($user, $data);

            [$old, $new] = $this->audit->dirtyDiff($user);
            $roleChanged = array_key_exists('role_id', $new);

            $user->save();

            if ($new !== []) {
                $this->audit->log(AuditAction::UserUpdated, 'users', $user, "User {$user->name} updated", $old, $new);
            }

            if ($roleChanged) {
                $this->audit->log(
                    AuditAction::PermissionChanged,
                    'users',
                    $user,
                    "Role of {$user->name} changed",
                    ['role' => Role::find($old['role_id'])?->name],
                    ['role' => $user->role()->first()?->name],
                );
                $this->permissions->flushUser($user);
            }

            return $user->refresh();
        });
    }

    public function setActive(User $user, bool $active, User $actor): void
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => 'You cannot change your own account status.']);
        }

        if ($user->is_active === $active) {
            return;
        }

        DB::transaction(function () use ($user, $active) {
            $user->is_active = $active;
            $user->save();

            if (! $active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            $this->audit->log(
                $active ? AuditAction::UserEnabled : AuditAction::UserDisabled,
                'users',
                $user,
                $active ? "User {$user->name} activated" : "User {$user->name} deactivated",
                ['is_active' => ! $active],
                ['is_active' => $active],
            );
        });
    }

    public function resetPassword(User $user, string $password): void
    {
        $user->password = $password;
        $user->setRememberToken(null);
        $user->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();

        $this->audit->log(AuditAction::UserPasswordReset, 'users', $user, "Password reset for {$user->name}");
    }

    /**
     * @param  array<string>  $grants
     * @param  array<string>  $denies
     */
    public function syncPermissionOverrides(User $user, array $grants, array $denies): void
    {
        $denies = array_values(array_diff($denies, $grants));
        $before = $this->overrideSnapshot($user);

        DB::transaction(function () use ($user, $grants, $denies) {
            $ids = Permission::whereIn('name', [...$grants, ...$denies])->pluck('id', 'name');

            $sync = [];
            foreach ($grants as $name) {
                $sync[$ids[$name]] = ['type' => 'grant'];
            }
            foreach ($denies as $name) {
                $sync[$ids[$name]] = ['type' => 'deny'];
            }

            $user->permissionOverrides()->sync($sync);
        });

        $after = $this->overrideSnapshot($user);
        $this->permissions->flushUser($user);

        if ($before !== $after) {
            $this->audit->log(
                AuditAction::PermissionChanged,
                'users',
                $user,
                "Permission overrides changed for {$user->name}",
                $before,
                $after,
            );
        }
    }

    /**
     * Legacy team_id / manager_id / team_users values are left untouched: they
     * are historical metadata and no longer affect access.
     */
    private function applyOrganisation(User $user, array $data): void
    {
        if (array_key_exists('role_id', $data)) {
            $user->role_id = $data['role_id'] ?: null;
        }
    }

    private function guardRoleAssignment(int $roleId, User $actor): void
    {
        $role = Role::findOrFail($roleId);

        if ($role->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Only a Super Admin can assign the Super Admin role.');
        }
    }

    /** @return array{grant: array<string>, deny: array<string>} */
    private function overrideSnapshot(User $user): array
    {
        $overrides = $user->permissionOverrides()->orderBy('name')->get();

        return [
            'grant' => $overrides->where('pivot.type', 'grant')->pluck('name')->values()->all(),
            'deny' => $overrides->where('pivot.type', 'deny')->pluck('name')->values()->all(),
        ];
    }
}
