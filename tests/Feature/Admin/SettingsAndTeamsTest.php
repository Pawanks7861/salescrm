<?php

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use App\Services\SettingService;

test('admin can update settings; each change is audited with old and new values', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/settings/lead', [
        'settings' => ['lead' => [
            'number_prefix' => 'LEAD',
            'duplicate_handling' => 'flag',
            'default_country_code' => '91',
            'require_lost_reason' => true,
            'stale_after_days' => 3,
        ]],
    ])->assertSessionHasNoErrors();

    $settings = app(SettingService::class);
    $settings->flush();
    expect($settings->get('lead.duplicate_handling'))->toBe('flag')
        ->and($settings->get('lead.number_prefix'))->toBe('LEAD');

    $log = AuditLog::where('action', 'SETTING_CHANGED')->where('description', 'like', '%Duplicate handling%')->firstOrFail();
    expect($log->old_values_json)->toBe(['lead.duplicate_handling' => 'merge'])
        ->and($log->new_values_json)->toBe(['lead.duplicate_handling' => 'flag']);

    expect(AuditLog::where('action', 'SETTING_CHANGED')->count())->toBe(2);
});

test('an admin alert reaches every active user and skips inactive ones', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->salesExecutive()->create();
    $inactive = User::factory()->salesExecutive()->inactive()->create();

    $this->actingAs($admin)->post('/admin/settings/alert-everyone')
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($admin->fresh()->notifications)->toHaveCount(1)
        ->and($admin->notifications->first()->data['event'])->toBe('admin_alert')
        ->and($other->fresh()->notifications)->toHaveCount(1)
        ->and($inactive->fresh()->notifications)->toHaveCount(0)
        ->and(AuditLog::where('action', 'NOTIFICATION_BROADCAST')->count())->toBe(1);
});

test('a salesperson cannot alert every user', function () {
    $this->actingAs(User::factory()->salesExecutive()->create())
        ->post('/admin/settings/alert-everyone')
        ->assertForbidden();
});

test('settings are validated against their definitions', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/settings/lead', [
        'settings' => ['lead' => ['duplicate_handling' => 'delete_everything']],
    ])->assertSessionHasErrors('settings.lead.duplicate_handling');
});

test('unknown settings groups are not routable', function () {
    $super = User::factory()->superAdmin()->create();

    $this->actingAs($super)->get('/admin/settings/secrets')->assertNotFound();
});

test('team management routes are removed for every role; legacy team rows are kept', function () {
    $legacy = Team::create(['name' => 'Ahmedabad Team', 'is_active' => true]);

    foreach ([User::factory()->superAdmin()->create(), User::factory()->admin()->create(), User::factory()->salesManager()->create()] as $user) {
        $this->actingAs($user)->get('/admin/teams')->assertNotFound();
        $this->actingAs($user)->post('/admin/teams', ['name' => 'X'])->assertNotFound();
        $this->actingAs($user)->put("/admin/teams/{$legacy->id}", ['name' => 'Y'])->assertNotFound();
        $this->actingAs($user)->delete("/admin/teams/{$legacy->id}")->assertNotFound();
    }

    expect(Team::count())->toBe(1)->and($legacy->fresh()->name)->toBe('Ahmedabad Team');
});
