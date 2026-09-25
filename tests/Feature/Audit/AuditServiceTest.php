<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;

test('sensitive keys are redacted recursively', function () {
    $clean = app(AuditService::class)->sanitize([
        'name' => 'Rahul',
        'password' => 'secret',
        'Access_Token' => 'abc',
        'nested' => ['app_secret' => 'xyz', 'city' => 'Ahmedabad', 'deeper' => ['api_key' => 'k']],
        'remember_token' => 'r',
    ]);

    expect($clean)->toBe([
        'name' => 'Rahul',
        'password' => AuditService::REDACTED,
        'Access_Token' => AuditService::REDACTED,
        'nested' => ['app_secret' => AuditService::REDACTED, 'city' => 'Ahmedabad', 'deeper' => ['api_key' => AuditService::REDACTED]],
        'remember_token' => AuditService::REDACTED,
    ]);
});

test('audit entries capture actor, entity, request and sanitised values', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $log = app(AuditService::class)->log(
        AuditAction::UserUpdated, 'users', $user, 'Changed', ['name' => 'Old'], ['name' => 'New', 'password' => 'p'],
    );

    expect($log->user_id)->toBe($user->id)
        ->and($log->entity_type)->toBe('User')
        ->and($log->entity_id)->toBe($user->id)
        ->and($log->new_values_json)->toBe(['name' => 'New', 'password' => AuditService::REDACTED]);
});

test('audit logs are immutable', function () {
    $log = app(AuditService::class)->log(AuditAction::SettingChanged, 'settings', null, 'x');

    expect(fn () => $log->update(['description' => 'tampered']))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
    expect(AuditLog::find($log->id)->description)->toBe('x');
});

test('there are no routes to modify or delete audit logs', function () {
    $super = User::factory()->superAdmin()->create();
    $log = app(AuditService::class)->log(AuditAction::SettingChanged, 'settings', null, 'x');

    $this->actingAs($super)->put("/admin/audit-logs/{$log->id}", ['description' => 'y'])->assertStatus(405);
    $this->actingAs($super)->delete("/admin/audit-logs/{$log->id}")->assertStatus(405);
});

test('dirty diff only returns changed attributes', function () {
    $user = User::factory()->create(['name' => 'Before', 'phone' => '111']);
    $user->name = 'After';

    [$old, $new] = app(AuditService::class)->dirtyDiff($user);

    expect($old)->toBe(['name' => 'Before'])->and($new)->toBe(['name' => 'After']);
});
