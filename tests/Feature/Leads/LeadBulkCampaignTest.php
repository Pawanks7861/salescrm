<?php

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Permission;
use App\Services\PermissionRegistrar;

beforeEach(function () {
    $this->org = salesOrg();
    $this->target = Campaign::create(['name' => 'Import Export', 'platform' => 'manual', 'is_active' => true]);
    $this->other = Campaign::create(['name' => 'Diwali', 'platform' => 'manual', 'is_active' => true]);
});

test('admin sets a campaign on a mix of leads in one request', function () {
    $open = Lead::factory()->create();
    $moving = Lead::factory()->create();
    $moving->forceFill(['campaign_id' => $this->other->id])->save();
    $already = Lead::factory()->create();
    $already->forceFill(['campaign_id' => $this->target->id])->save();

    $this->actingAs($this->org->admin)
        ->post('/leads/bulk-campaign', [
            'lead_ids' => [$open->id, $moving->id, $already->id],
            'campaign_id' => $this->target->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', '2 leads set to Import Export.');

    expect($open->fresh()->campaign_id)->toBe($this->target->id)
        ->and($moving->fresh()->campaign_id)->toBe($this->target->id)
        ->and($already->fresh()->campaign_id)->toBe($this->target->id)
        ->and($open->fresh()->updated_by)->toBe($this->org->admin->id);

    $this->assertDatabaseHas('activities', [
        'subject_id' => $open->id,
        'type' => 'lead_updated',
        'description' => 'Campaign set to Import Export',
        'user_id' => $this->org->admin->id,
    ]);
    $this->assertDatabaseHas('activities', [
        'subject_id' => $moving->id,
        'type' => 'lead_updated',
        'description' => 'Campaign changed from Diwali to Import Export',
    ]);
    $this->assertDatabaseMissing('activities', [
        'subject_id' => $already->id,
        'type' => 'lead_updated',
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_UPDATED', 'entity_id' => $open->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_UPDATED', 'entity_id' => $moving->id]);
});

test('super admin can bulk update lead campaigns', function () {
    $lead = Lead::factory()->create();

    $this->actingAs($this->org->super)
        ->post('/leads/bulk-campaign', [
            'lead_ids' => [$lead->id],
            'campaign_id' => $this->target->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', '1 lead set to Import Export.');

    expect($lead->fresh()->campaign_id)->toBe($this->target->id);
});

test('sales roles cannot bulk update campaigns', function (string $who) {
    $lead = Lead::factory()->create();

    $this->actingAs($this->org->{$who})
        ->post('/leads/bulk-campaign', [
            'lead_ids' => [$lead->id],
            'campaign_id' => $this->target->id,
        ])
        ->assertForbidden();

    expect($lead->fresh()->campaign_id)->toBeNull();
})->with(['manager', 'rahul']);

test('a missing or archived lead rejects the whole campaign update', function () {
    $live = Lead::factory()->create();
    $live->forceFill(['campaign_id' => $this->other->id])->save();
    $archived = Lead::factory()->archived()->create();

    $this->actingAs($this->org->admin)
        ->from('/leads')
        ->post('/leads/bulk-campaign', [
            'lead_ids' => [$live->id, $archived->id],
            'campaign_id' => $this->target->id,
        ])
        ->assertRedirect('/leads')
        ->assertSessionHasErrors('lead_ids');

    expect($live->fresh()->campaign_id)->toBe($this->other->id)
        ->and($archived->fresh()->campaign_id)->toBeNull();
});

test('an inactive campaign cannot be applied in bulk', function () {
    $lead = Lead::factory()->create();
    $inactive = Campaign::create(['name' => 'Retired', 'platform' => 'manual', 'is_active' => false]);

    $this->actingAs($this->org->admin)
        ->from('/leads')
        ->post('/leads/bulk-campaign', [
            'lead_ids' => [$lead->id],
            'campaign_id' => $inactive->id,
        ])
        ->assertRedirect('/leads')
        ->assertSessionHasErrors('campaign_id');

    expect($lead->fresh()->campaign_id)->toBeNull();
});

test('bulk campaign update still requires lead.edit_source', function () {
    $lead = Lead::factory()->create();
    $deny = Permission::where('name', 'lead.edit_source')->first();
    $this->org->admin->permissionOverrides()->attach($deny->id, ['type' => 'deny']);
    app(PermissionRegistrar::class)->flushUser($this->org->admin);

    $this->actingAs($this->org->admin)
        ->post('/leads/bulk-campaign', [
            'lead_ids' => [$lead->id],
            'campaign_id' => $this->target->id,
        ])
        ->assertForbidden();

    expect($lead->fresh()->campaign_id)->toBeNull();
});

test('the leads list offers bulk campaign update only to admin and super admin', function (string $who, bool $allowed) {
    $can = $this->actingAs($this->org->{$who})->get('/leads')->assertOk()->inertiaProps('can');

    expect($can['bulkCampaign'])->toBe($allowed);
})->with([
    ['admin', true],
    ['super', true],
    ['manager', false],
    ['rahul', false],
]);
