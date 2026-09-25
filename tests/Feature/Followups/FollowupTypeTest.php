<?php

use App\Models\FollowupType;
use App\Models\Lead;
use App\Services\SettingService;
use App\Support\SettingDefinitions;

beforeEach(function () {
    $this->org = salesOrg();
});

test('default types are seeded as system types', function () {
    expect(FollowupType::ordered()->pluck('slug')->all())->toBe(['call', 'whatsapp', 'email', 'demo', 'site_visit', 'other'])
        ->and(FollowupType::where('is_system', false)->count())->toBe(0);
});

test('admin can create, update, toggle and reorder types', function () {
    $this->actingAs($this->org->admin)->post('/admin/followup-settings/types', ['name' => 'Video Call', 'icon' => 'video', 'color' => 'purple', 'is_active' => true])
        ->assertSessionHasNoErrors();
    $type = FollowupType::where('name', 'Video Call')->sole();
    expect($type->slug)->toBe('video_call')->and($type->is_system)->toBeFalse();

    $this->actingAs($this->org->admin)->put("/admin/followup-settings/types/{$type->id}", ['name' => 'Video Meeting', 'color' => 'blue', 'is_active' => false])
        ->assertSessionHasNoErrors();
    expect($type->fresh()->name)->toBe('Video Meeting')->and($type->fresh()->is_active)->toBeFalse();

    $ids = FollowupType::ordered()->pluck('id')->reverse()->values()->all();
    $this->actingAs($this->org->admin)->post('/admin/followup-settings/types/reorder', ['ids' => $ids])->assertSessionHasNoErrors();
    expect(FollowupType::ordered()->pluck('id')->all())->toBe($ids);
});

test('duplicate names and invalid colors are rejected', function () {
    $this->actingAs($this->org->admin)->post('/admin/followup-settings/types', ['name' => 'Call', 'color' => 'blue'])
        ->assertSessionHasErrors('name');
    $this->actingAs($this->org->admin)->post('/admin/followup-settings/types', ['name' => 'X', 'color' => 'neon'])
        ->assertSessionHasErrors('color');
});

test('system types and types in use cannot be deleted; unused custom types can', function () {
    $call = FollowupType::where('slug', 'call')->sole();
    $this->actingAs($this->org->admin)->delete("/admin/followup-settings/types/{$call->id}")->assertSessionHasErrors();
    expect(FollowupType::find($call->id))->not->toBeNull();

    $this->actingAs($this->org->admin)->post('/admin/followup-settings/types', ['name' => 'Visit', 'color' => 'amber']);
    $custom = FollowupType::where('name', 'Visit')->sole();

    $lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    scheduleFollowup($lead, $this->org->rahul, ['followup_type_id' => $custom->id]);
    $this->actingAs($this->org->admin)->delete("/admin/followup-settings/types/{$custom->id}")->assertSessionHasErrors();

    $this->actingAs($this->org->admin)->post('/admin/followup-settings/types', ['name' => 'Unused', 'color' => 'slate']);
    $unused = FollowupType::where('name', 'Unused')->sole();
    $this->actingAs($this->org->admin)->delete("/admin/followup-settings/types/{$unused->id}")->assertSessionHasNoErrors();
    expect(FollowupType::find($unused->id))->toBeNull();
});

test('sales users cannot manage types or settings', function () {
    $this->actingAs($this->org->rahul)->post('/admin/followup-settings/types', ['name' => 'Hack', 'color' => 'blue'])->assertForbidden();
    $this->actingAs($this->org->rahul)->put('/admin/followup-settings/settings', ['settings' => ['allow_past' => true]])->assertForbidden();
    expect(FollowupType::where('name', 'Hack')->exists())->toBeFalse();
});

test('admin can save follow-up settings with validation', function () {
    $form = [
        'default_reminder_minutes' => 15,
        'overdue_alert_after_minutes' => 30,
        'due_soon_minutes' => 60,
        'allow_past' => false,
        'require_outcome' => false,
        'require_cancellation_reason' => true,
    ];

    $this->actingAs($this->org->admin)->put('/admin/followup-settings/settings', ['settings' => $form])
        ->assertSessionHasNoErrors();

    expect(app(SettingService::class)->get('followup.overdue_alert_after_minutes'))->toBe(30)
        ->and(app(SettingService::class)->get('followup.require_outcome'))->toBeFalse();

    $this->actingAs($this->org->admin)->put('/admin/followup-settings/settings', ['settings' => array_merge($form, ['overdue_alert_after_minutes' => -5])])
        ->assertSessionHasErrors();
});

test('the removed mark_missed setting is no longer defined', function () {
    expect(SettingDefinitions::all())->not->toHaveKey('followup.mark_missed_after_minutes');
});
