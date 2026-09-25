<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RolePermissionsRequest;
use App\Http\Requests\Admin\RoleRequest;
use App\Models\Role;
use App\Services\RoleService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()
            ->withCount(['users', 'permissions'])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                ...$role->only('id', 'name', 'slug', 'description', 'is_system', 'users_count', 'permissions_count'),
                'is_super_admin' => $role->isSuperAdmin(),
                'can' => [
                    'update' => $request->user()->can('update', $role),
                    'delete' => $request->user()->can('delete', $role),
                ],
            ]);

        return Inertia::render('Admin/Roles/Index', [
            'roles' => $roles,
            'totalPermissions' => count(Permissions::all()),
            'can' => ['create' => $request->user()->can('create', Role::class)],
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $role = $this->roles->create($request->validated());

        return redirect()->route('admin.roles.edit', $role)->with('success', "Role {$role->name} created. Now choose its permissions.");
    }

    public function edit(Request $request, Role $role): Response
    {
        $this->authorize('view', $role);

        $modules = collect(Permissions::all())
            ->map(fn ($meta, $name) => ['name' => $name, ...$meta])
            ->groupBy('module')
            ->map(fn ($items, $module) => [
                'module' => $module,
                'permissions' => $items->map(fn ($i) => ['name' => $i['name'], 'label' => $i['label']])->values(),
            ])
            ->values();

        return Inertia::render('Admin/Roles/Edit', [
            'role' => [
                ...$role->only('id', 'name', 'slug', 'description', 'is_system'),
                'is_super_admin' => $role->isSuperAdmin(),
                'permissions' => $role->isSuperAdmin() ? Permissions::names() : $role->permissions()->pluck('name'),
                'users_count' => $role->users()->count(),
            ],
            'modules' => $modules,
            'can' => ['update' => $request->user()->can('update', $role)],
        ]);
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $this->roles->update($role, $request->validated());

        return back()->with('success', 'Role details saved.');
    }

    public function updatePermissions(RolePermissionsRequest $request, Role $role): RedirectResponse
    {
        $this->roles->syncPermissions($role, $request->validated('permissions'));

        return back()->with('success', "Permissions for {$role->name} saved.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        $this->roles->delete($role);

        return redirect()->route('admin.roles.index')->with('success', "Role {$role->name} deleted.");
    }
}
