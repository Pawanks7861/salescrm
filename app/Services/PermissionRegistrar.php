<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves and caches a user's effective permissions:
 * role permissions + user grants - user denies.
 */
class PermissionRegistrar
{
    private const VERSION_KEY = 'permissions.version';

    private const TTL_SECONDS = 3600;

    /** @return array<string> */
    public function permissionsFor(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return Permissions::names();
        }

        return Cache::remember($this->userKey($user->id), self::TTL_SECONDS, function () use ($user) {
            $rolePermissions = $user->role_id
                ? DB::table('role_permissions')
                    ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                    ->where('role_permissions.role_id', $user->role_id)
                    ->pluck('permissions.name')
                    ->all()
                : [];

            $overrides = DB::table('user_permissions')
                ->join('permissions', 'permissions.id', '=', 'user_permissions.permission_id')
                ->where('user_permissions.user_id', $user->id)
                ->pluck('user_permissions.type', 'permissions.name');

            $grants = $overrides->filter(fn ($type) => $type === 'grant')->keys()->all();
            $denies = $overrides->filter(fn ($type) => $type === 'deny')->keys()->all();

            return array_values(array_diff(array_unique([...$rolePermissions, ...$grants]), $denies, Permissions::DEPRECATED));
        });
    }

    public function flushUser(User $user): void
    {
        Cache::forget($this->userKey($user->id));
        $user->forgetResolvedPermissions();
    }

    /** Invalidates every cached permission set (used when role permissions change). */
    public function flushAll(): void
    {
        Cache::forever(self::VERSION_KEY, $this->version() + 1);
    }

    /**
     * Ensures every permission defined in code exists in the database.
     *
     * @return array<string> names of permissions created by this call
     */
    public function syncCatalogue(): array
    {
        $created = [];

        foreach (Permissions::all() as $name => $meta) {
            $permission = Permission::updateOrCreate(['name' => $name], $meta);

            if ($permission->wasRecentlyCreated) {
                $created[] = $name;
            }
        }

        $this->flushAll();

        return $created;
    }

    private function userKey(int $userId): string
    {
        return "permissions.user.{$userId}.v{$this->version()}";
    }

    private function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }
}
