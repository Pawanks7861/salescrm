<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\UserPermissionsRequest;
use App\Http\Requests\Admin\UserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $users = User::query()
            ->with(['role:id,name,slug'])
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('employee_code', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->when($filters['role_id'] ?? null, fn ($q, $v) => $q->where('role_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('is_active', $v === 'active'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => $this->present($user, $request->user()));

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'can' => ['create' => $request->user()->can('create', User::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Admin/Users/Form', $this->formOptions($request->user()));
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = $this->users->create($request->validated(), $request->user());

        return redirect()->route('admin.users.index')->with('success', "User {$user->name} created.");
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorize('update', $user);

        $user->load('permissionOverrides:id,name', 'role:id,name,slug');

        return Inertia::render('Admin/Users/Form', [
            ...$this->formOptions($request->user()),
            'user' => [
                ...$user->only('id', 'name', 'employee_code', 'email', 'phone', 'designation', 'role_id', 'is_active'),
                'is_super_admin' => $user->isSuperAdmin(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'overrides' => [
                'grant' => $user->permissionOverrides->where('pivot.type', 'grant')->pluck('name')->diff(Permissions::DEPRECATED)->values(),
                'deny' => $user->permissionOverrides->where('pivot.type', 'deny')->pluck('name')->diff(Permissions::DEPRECATED)->values(),
            ],
            'permissionCatalogue' => $request->user()->can('managePermissions', $user) ? $this->catalogue() : null,
            'can' => [
                'toggleActive' => $request->user()->can('toggleActive', $user),
                'resetPassword' => $request->user()->can('resetPassword', $user),
                'managePermissions' => $request->user()->can('managePermissions', $user),
            ],
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->users->update($user, $request->validated(), $request->user());

        return redirect()->route('admin.users.index')->with('success', "User {$user->name} updated.");
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $this->authorize('toggleActive', $user);

        $this->users->setActive($user, ! $user->is_active, $request->user());

        return back()->with('success', $user->is_active ? "{$user->name} activated." : "{$user->name} deactivated.");
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $this->users->resetPassword($user, $request->validated('password'));

        return back()->with('success', "Password reset for {$user->name}. Their active sessions were ended.");
    }

    public function updatePermissions(UserPermissionsRequest $request, User $user): RedirectResponse
    {
        $this->users->syncPermissionOverrides($user, $request->validated('grant', []), $request->validated('deny', []));

        return back()->with('success', 'Permission overrides saved.');
    }

    private function present(User $user, User $actor): array
    {
        return [
            ...$user->only('id', 'name', 'employee_code', 'email', 'phone', 'designation', 'is_active'),
            'role' => $user->role?->only('id', 'name'),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'can' => [
                'update' => $actor->can('update', $user),
                'toggleActive' => $actor->can('toggleActive', $user),
            ],
        ];
    }

    private function formOptions(User $actor): array
    {
        return [
            'roles' => Role::query()
                ->when(! $actor->isSuperAdmin(), fn ($q) => $q->where('slug', '!=', User::SUPER_ADMIN_ROLE))
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    /** @return array<string, array<array{name: string, label: string}>> */
    private function catalogue(): array
    {
        return collect(Permissions::all())
            ->map(fn ($meta, $name) => ['name' => $name, 'label' => $meta['label'], 'module' => $meta['module']])
            ->groupBy('module')
            ->map(fn ($items) => $items->map(fn ($i) => ['name' => $i['name'], 'label' => $i['label']])->values())
            ->all();
    }
}
