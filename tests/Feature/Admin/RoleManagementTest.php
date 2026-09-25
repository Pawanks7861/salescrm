<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;

test('super admin can create a role and assign permissions; changes are audited as added/removed', function () {
    $super = User::factory()->superAdmin()->create();

    $this->actingAs($super)->post('/admin/roles', ['name' => 'Telecaller'])->assertRedirect();
    $role = Role::where('slug', 'telecaller')->firstOrFail();

    $this->actingAs($super)->put("/admin/roles/{$role->id}/permissions", [
        'permissions' => [Permissions::LEAD_VIEW, Permissions::FOLLOWUP_CREATE],
    ])->assertSessionHasNoErrors();

    $this->actingAs($super)->put("/admin/roles/{$role->id}/permissions", [
        'permissions' => [Permissions::LEAD_VIEW, Permissions::MEETING_VIEW],
    ])->assertSessionHasNoErrors();

    expect($role->permissions()->pluck('name')->sort()->values()->all())
        ->toBe([Permissions::LEAD_VIEW, Permissions::MEETING_VIEW]);

    $log = AuditLog::where('action', 'PERMISSION_CHANGED')->where('entity_type', 'Role')->latest('id')->firstOrFail();
    expect($log->new_values_json)->toBe(['added' => [Permissions::MEETING_VIEW]])
        ->and($log->old_values_json)->toBe(['removed' => [Permissions::FOLLOWUP_CREATE]]);
});

test('unknown permission names are rejected', function () {
    $super = User::factory()->superAdmin()->create();
    $role = Role::where('slug', 'sales_executive')->first();

    $this->actingAs($super)->put("/admin/roles/{$role->id}/permissions", ['permissions' => ['lead.hack_everything']])
        ->assertSessionHasErrors('permissions.0');
});

test('the super admin role cannot be modified and system roles cannot be deleted', function () {
    $super = User::factory()->superAdmin()->create();
    $superRole = Role::where('slug', 'super_admin')->first();
    $salesRole = Role::where('slug', 'sales_executive')->first();

    $this->actingAs($super)->put("/admin/roles/{$superRole->id}/permissions", ['permissions' => []])->assertForbidden();
    $this->actingAs($super)->put("/admin/roles/{$superRole->id}", ['name' => 'Renamed'])->assertForbidden();
    $this->actingAs($super)->delete("/admin/roles/{$salesRole->id}")->assertForbidden();

    expect(Role::where('slug', 'sales_executive')->exists())->toBeTrue();
});

test('a custom role in use cannot be deleted', function () {
    $super = User::factory()->superAdmin()->create();
    $role = Role::create(['name' => 'Temp', 'slug' => 'temp']);
    User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($super)->delete("/admin/roles/{$role->id}")->assertSessionHasErrors('role');
    expect(Role::find($role->id))->not->toBeNull();
});
