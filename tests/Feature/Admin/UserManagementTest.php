<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function userPayload(array $overrides = []): array
{
    return [
        'name' => 'Rahul Sharma',
        'email' => 'rahul@example.com',
        'employee_code' => 'EMP-001',
        'phone' => '+91 98765 43210',
        'designation' => 'Sales Executive',
        'role_id' => Role::where('slug', 'sales_executive')->value('id'),
        'password' => 'StrongPass123',
        'password_confirmation' => 'StrongPass123',
        ...$overrides,
    ];
}

test('admin can create a user; the audit entry never contains the password', function () {
    $admin = User::factory()->admin()->create();
    $team = Team::create(['name' => 'Inside Sales']);

    $this->actingAs($admin)
        ->post('/admin/users', userPayload(['team_id' => $team->id, 'team_ids' => [$team->id]]))
        ->assertRedirect('/admin/users')
        ->assertSessionHasNoErrors();

    // Team fields are no longer accepted; the user is created without any team.
    $user = User::where('email', 'rahul@example.com')->firstOrFail();
    expect($user->team_id)->toBeNull()
        ->and($user->teams()->count())->toBe(0)
        ->and($user->is_active)->toBeTrue();

    $log = AuditLog::where('action', 'USER_CREATED')->where('entity_id', $user->id)->firstOrFail();
    expect($log->user_id)->toBe($admin->id)
        ->and(json_encode($log->new_values_json))->not->toContain('StrongPass123')
        ->and($log->new_values_json)->not->toHaveKey('password');
});

test('weak passwords are rejected', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post('/admin/users', userPayload(['password' => 'password', 'password_confirmation' => 'password']))
        ->assertSessionHasErrors('password');
});

test('only a super admin can assign the super admin role', function () {
    $admin = User::factory()->admin()->create();
    $super = User::factory()->superAdmin()->create();
    $superRoleId = Role::where('slug', 'super_admin')->value('id');

    $this->actingAs($admin)->post('/admin/users', userPayload(['role_id' => $superRoleId]))->assertForbidden();
    expect(User::where('email', 'rahul@example.com')->exists())->toBeFalse();

    $this->actingAs($super)->post('/admin/users', userPayload(['role_id' => $superRoleId]))->assertRedirect('/admin/users');
});

test('an admin cannot edit a super admin account', function () {
    $admin = User::factory()->admin()->create();
    $super = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->get("/admin/users/{$super->id}/edit")->assertForbidden();
    $this->actingAs($admin)->put("/admin/users/{$super->id}", userPayload(['email' => $super->email, 'password' => null, 'password_confirmation' => null]))
        ->assertForbidden();
});

test('role change is audited as a permission change', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->salesExecutive()->create();
    $managerRole = Role::where('slug', 'sales_manager')->first();

    $this->actingAs($admin)->put("/admin/users/{$user->id}", [
        'name' => $user->name,
        'email' => $user->email,
        'role_id' => $managerRole->id,
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->role_id)->toBe($managerRole->id);

    $log = AuditLog::where('action', 'PERMISSION_CHANGED')->where('entity_id', $user->id)->firstOrFail();
    expect($log->old_values_json)->toBe(['role' => 'Sales Executive'])
        ->and($log->new_values_json)->toBe(['role' => 'Sales Manager']);
});

test('users cannot change their own role', function () {
    $super = User::factory()->superAdmin()->create();
    $salesRole = Role::where('slug', 'sales_executive')->value('id');

    $this->actingAs($super)->put("/admin/users/{$super->id}", [
        'name' => $super->name, 'email' => $super->email, 'role_id' => $salesRole,
    ])->assertSessionHasErrors('role_id');
});

test('deactivating a user ends their sessions and is audited; users cannot deactivate themselves', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->salesExecutive()->create();

    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($admin)->post("/admin/users/{$user->id}/toggle-active")->assertRedirect();

    expect($user->refresh()->is_active)->toBeFalse();
    $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'USER_DISABLED', 'entity_id' => $user->id]);

    $this->actingAs($admin)->post("/admin/users/{$admin->id}/toggle-active")->assertForbidden();
});

test('admin can reset a password; it is audited without the value', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->salesExecutive()->create();

    $this->actingAs($admin)->post("/admin/users/{$user->id}/reset-password", [
        'password' => 'BrandNew12345', 'password_confirmation' => 'BrandNew12345',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('BrandNew12345', $user->refresh()->password))->toBeTrue();
    $log = AuditLog::where('action', 'USER_PASSWORD_RESET')->firstOrFail();
    expect($log->toJson())->not->toContain('BrandNew12345');
});

test('profile update ignores attempts to change role, email or activation (mass assignment)', function () {
    $sales = User::factory()->salesExecutive()->create();
    $originalRole = $sales->role_id;

    $this->actingAs($sales)->patch('/profile', [
        'name' => 'Updated Name',
        'email' => 'hijack@example.com',
        'role_id' => Role::where('slug', 'super_admin')->value('id'),
        'is_active' => true,
        'team_id' => 999,
    ])->assertSessionHasNoErrors();

    $sales->refresh();
    expect($sales->name)->toBe('Updated Name')
        ->and($sales->email)->not->toBe('hijack@example.com')
        ->and($sales->role_id)->toBe($originalRole)
        ->and($sales->team_id)->toBeNull();
});

test('users cannot delete their own account', function () {
    $sales = User::factory()->salesExecutive()->create();

    $this->actingAs($sales)->delete('/profile')->assertStatus(405);
    expect(User::find($sales->id))->not->toBeNull();
});
