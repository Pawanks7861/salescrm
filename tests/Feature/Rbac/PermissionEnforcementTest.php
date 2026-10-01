<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionRegistrar;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

dataset('admin endpoints', [
    'users index' => ['get', '/admin/users'],
    'users create' => ['get', '/admin/users/create'],
    'users store' => ['post', '/admin/users'],
    'roles index' => ['get', '/admin/roles'],
    'roles store' => ['post', '/admin/roles'],
    'settings' => ['get', '/admin/settings'],
    'settings update' => ['put', '/admin/settings/general'],
    'audit logs' => ['get', '/admin/audit-logs'],
    'login history' => ['get', '/admin/login-history'],
]);

test('sales executives are denied every admin endpoint', function (string $method, string $uri) {
    $sales = User::factory()->salesExecutive()->create();

    $this->actingAs($sales)->{$method}($uri, [])->assertForbidden();
})->with('admin endpoints');

test('every denied request is written to the audit log', function () {
    $sales = User::factory()->salesExecutive()->create();

    $this->actingAs($sales)->get('/admin/audit-logs')->assertForbidden();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'ACCESS_DENIED',
        'user_id' => $sales->id,
        'request_method' => 'GET',
    ]);
});

test('export URLs return 403 without export permission and are logged as export attempts', function () {
    Route::middleware(['web', 'auth', 'active', 'permission:lead.export'])
        ->get('/leads/export', fn () => 'csv-data');

    $sales = User::factory()->salesExecutive()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($sales)->get('/leads/export')->assertForbidden();
    $this->assertDatabaseHas('audit_logs', ['action' => 'EXPORT_ATTEMPTED', 'user_id' => $sales->id]);

    $this->actingAs($admin)->get('/leads/export')->assertOk();
});

test('default sales executive role has no export, import, bulk, delete, audit or admin permissions', function () {
    $sales = User::factory()->salesExecutive()->create();

    foreach ([
        Permissions::LEAD_EXPORT, Permissions::LEAD_IMPORT, Permissions::LEAD_BULK_ACTION,
        Permissions::LEAD_DELETE, Permissions::LEAD_REASSIGN, Permissions::LEAD_VIEW_ALL,
        Permissions::LEAD_VIEW_TEAM, Permissions::REPORT_EXPORT, Permissions::AUDIT_VIEW,
        Permissions::SETTINGS_MANAGE, Permissions::USER_VIEW, Permissions::FILE_DOWNLOAD,
    ] as $permission) {
        expect($sales->hasPermission($permission))->toBeFalse("Sales executive unexpectedly has {$permission}");
    }

    expect($sales->hasPermission(Permissions::LEAD_VIEW))->toBeTrue();
});

test('admin does not automatically receive role management or facebook permissions', function () {
    $admin = User::factory()->admin()->create();
    $role = Role::where('slug', 'sales_executive')->first();

    expect($admin->hasPermission(Permissions::ROLE_MANAGE))->toBeFalse()
        ->and($admin->hasPermission(Permissions::FACEBOOK_MANAGE))->toBeFalse();

    $this->actingAs($admin)
        ->put("/admin/roles/{$role->id}/permissions", ['permissions' => Permissions::names()])
        ->assertForbidden();
});

test('super admin bypasses every permission check', function () {
    $super = User::factory()->superAdmin()->create();

    foreach (Permissions::names() as $permission) {
        expect($super->can($permission))->toBeTrue();
    }

    $this->actingAs($super)->get('/admin/audit-logs')->assertOk();
});

test('per-user grant and deny overrides change effective permissions', function () {
    $sales = User::factory()->salesExecutive()->create();
    $registrar = app(PermissionRegistrar::class);

    $sales->permissionOverrides()->attach(Permission::where('name', Permissions::AUDIT_VIEW)->value('id'), ['type' => 'grant']);
    $sales->permissionOverrides()->attach(Permission::where('name', Permissions::LEAD_CREATE)->value('id'), ['type' => 'deny']);
    $registrar->flushUser($sales);

    expect($sales->hasPermission(Permissions::AUDIT_VIEW))->toBeTrue()
        ->and($sales->hasPermission(Permissions::LEAD_CREATE))->toBeFalse();

    $this->actingAs($sales)->get('/admin/audit-logs')->assertOk();
});

test('permission cache is invalidated when role permissions change', function () {
    $sales = User::factory()->salesExecutive()->create();
    expect($sales->hasPermission(Permissions::AUDIT_VIEW))->toBeFalse();

    $super = User::factory()->superAdmin()->create();
    $role = Role::where('slug', 'sales_executive')->first();
    $current = $role->permissions()->pluck('name')->all();

    $this->actingAs($super)
        ->put("/admin/roles/{$role->id}/permissions", ['permissions' => [...$current, Permissions::AUDIT_VIEW]])
        ->assertSessionHasNoErrors();

    expect(User::find($sales->id)->hasPermission(Permissions::AUDIT_VIEW))->toBeTrue();
});

test('shared inertia props never expose password hashes or tokens', function () {
    $user = User::factory()->salesExecutive()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.id', $user->id)
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token'));
});

test('sidebar navigation only contains permitted items', function () {
    $sales = User::factory()->salesExecutive()->create();
    $super = User::factory()->superAdmin()->create();

    $labels = fn ($user) => collect($this->actingAs($user)->get('/dashboard')->inertiaProps('navigation'))
        ->flatMap(fn ($section) => collect($section['items'])->pluck('label'))
        ->all();

    expect($labels($sales))->not->toContain('Users', 'Audit Logs', 'Roles & Permissions', 'System Settings');
    expect($labels($super))->toContain('Users', 'Audit Logs', 'Roles & Permissions', 'System Settings', 'Login History');
});
