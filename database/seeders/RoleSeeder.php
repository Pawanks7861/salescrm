<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionRegistrar;
use App\Support\Permissions as P;
use Illuminate\Database\Seeder;

/**
 * Creates the default system roles. Full default permissions are only assigned
 * when a role is first created, so admin customisations survive re-seeding.
 * Permissions newly introduced by a release are granted to existing system
 * roles according to these defaults.
 */
class RoleSeeder extends Seeder
{
    public static function defaults(): array
    {
        $salesExecutive = [
            P::LEAD_VIEW, P::LEAD_CREATE, P::LEAD_EDIT, P::LEAD_CHANGE_STATUS,
            P::FOLLOWUP_VIEW, P::FOLLOWUP_CREATE, P::FOLLOWUP_EDIT, P::FOLLOWUP_COMPLETE, P::FOLLOWUP_CANCEL,
            P::MEETING_VIEW, P::MEETING_CREATE, P::MEETING_EDIT, P::MEETING_CANCEL, P::MEETING_COMPLETE,
            P::FILE_VIEW, P::FILE_UPLOAD,
            P::CALL_VIEW, P::CALL_MAKE, P::CALL_RECEIVE, P::CALL_ADD_DISPOSITION, P::CALL_EDIT_NOTES,
            P::REPORT_VIEW,
        ];

        $salesManager = [
            ...$salesExecutive,
            P::LEAD_ASSIGN, P::LEAD_REASSIGN, P::LEAD_EDIT_SOURCE,
            P::FOLLOWUP_DELETE, P::FOLLOWUP_ASSIGN,
            P::MEETING_OVERRIDE_CONFLICT, P::MEETING_ASSIGN, P::MEETING_CREATE_WITHOUT_LEAD,
            P::NOTE_EDIT_ANY, P::NOTE_DELETE, P::NOTE_VIEW_MANAGEMENT,
            P::USER_VIEW,
            P::FILE_DOWNLOAD,
            P::CALL_RECORDING_LISTEN,
        ];

        $excludedFromAdmin = [P::ROLE_MANAGE, P::FACEBOOK_MANAGE, P::LEAD_RESTORE, P::USER_DELETE, P::CALL_RECORDING_DOWNLOAD];
        $admin = array_values(array_diff(P::names(), $excludedFromAdmin));

        return [
            'super_admin' => ['name' => 'Super Admin', 'description' => 'Full, unrestricted system access.', 'permissions' => []],
            'admin' => ['name' => 'Admin', 'description' => 'Operational administration; permissions controlled by Super Admin.', 'permissions' => $admin],
            'sales_manager' => ['name' => 'Sales Manager', 'description' => 'Own records only unless granted a view-all permission; no team visibility.', 'permissions' => array_values(array_unique($salesManager))],
            'sales_executive' => ['name' => 'Sales Executive', 'description' => 'Works assigned leads only.', 'permissions' => $salesExecutive],
        ];
    }

    public function run(PermissionRegistrar $registrar): void
    {
        foreach (self::defaults() as $slug => $definition) {
            $role = Role::firstOrNew(['slug' => $slug]);
            $isNew = ! $role->exists;

            $role->name = $definition['name'];
            $role->description = $definition['description'];
            $role->is_system = true;
            $role->save();

            if ($isNew && $definition['permissions'] !== []) {
                $role->permissions()->sync(Permission::whereIn('name', $definition['permissions'])->pluck('id'));
            } elseif (! $isNew) {
                $newlyIntroduced = array_intersect($definition['permissions'], PermissionSeeder::$created);
                $role->permissions()->syncWithoutDetaching(Permission::whereIn('name', $newlyIntroduced)->pluck('id'));
            }
        }

        $registrar->flushAll();
    }
}
