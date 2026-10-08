<?php

use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\Permission;
use App\Models\User;
use App\Services\PermissionRegistrar;

beforeEach(function () {
    $this->org = salesOrg();
});

test('admin assigns a mix of owned and unassigned leads in one request', function () {
    $owned = Lead::factory()->assignedTo($this->org->rahul)->create();
    $open = Lead::factory()->create();
    $already = Lead::factory()->assignedTo($this->org->priya)->create();

    $this->actingAs($this->org->admin)
        ->post('/leads/bulk-assign', [
            'lead_ids' => [$owned->id, $open->id, $already->id],
            'assigned_to' => $this->org->priya->id,
            'reason' => 'Morning distribution',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', '2 leads assigned to Priya Patel.');

    expect($owned->fresh()->assigned_to)->toBe($this->org->priya->id)
        ->and($open->fresh()->assigned_to)->toBe($this->org->priya->id)
        ->and($already->fresh()->assigned_to)->toBe($this->org->priya->id)
        ->and(LeadAssignment::count())->toBe(2);

    $history = LeadAssignment::where('lead_id', $owned->id)->sole();
    expect($history->from_user_id)->toBe($this->org->rahul->id)
        ->and($history->to_user_id)->toBe($this->org->priya->id)
        ->and($history->reason)->toBe('Morning distribution')
        ->and($history->assigned_by)->toBe($this->org->admin->id);

    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_REASSIGNED', 'entity_id' => $owned->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_ASSIGNED', 'entity_id' => $open->id]);
});

test('super admin can bulk assign leads', function () {
    $lead = Lead::factory()->create();

    $this->actingAs($this->org->super)
        ->post('/leads/bulk-assign', [
            'lead_ids' => [$lead->id],
            'assigned_to' => $this->org->rahul->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', '1 lead assigned to Rahul Sharma.');

    expect($lead->fresh()->assigned_to)->toBe($this->org->rahul->id);
});

test('sales roles cannot bulk assign even when they can assign a single lead', function (string $who) {
    $lead = Lead::factory()->create();

    $this->actingAs($this->org->{$who})
        ->post('/leads/bulk-assign', [
            'lead_ids' => [$lead->id],
            'assigned_to' => $this->org->priya->id,
        ])
        ->assertForbidden();

    expect($lead->fresh()->assigned_to)->toBeNull()
        ->and(LeadAssignment::count())->toBe(0);
})->with(['manager', 'rahul']);

test('a missing or archived lead rejects the whole request', function () {
    $live = Lead::factory()->assignedTo($this->org->rahul)->create();
    $archived = Lead::factory()->archived()->create();

    $this->actingAs($this->org->admin)
        ->from('/leads')
        ->post('/leads/bulk-assign', [
            'lead_ids' => [$live->id, $archived->id],
            'assigned_to' => $this->org->priya->id,
        ])
        ->assertRedirect('/leads')
        ->assertSessionHasErrors('lead_ids');

    expect($live->fresh()->assigned_to)->toBe($this->org->rahul->id)
        ->and(LeadAssignment::count())->toBe(0);
});

test('an inactive user cannot receive bulk assigned leads', function () {
    $lead = Lead::factory()->create();
    $inactive = User::factory()->salesExecutive()->inactive()->create();

    $this->actingAs($this->org->admin)
        ->from('/leads')
        ->post('/leads/bulk-assign', [
            'lead_ids' => [$lead->id],
            'assigned_to' => $inactive->id,
        ])
        ->assertRedirect('/leads')
        ->assertSessionHasErrors('assigned_to');

    expect($lead->fresh()->assigned_to)->toBeNull();
});

test('bulk reassignment still requires lead.reassign', function () {
    $lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $deny = Permission::where('name', 'lead.reassign')->first();
    $this->org->admin->permissionOverrides()->attach($deny->id, ['type' => 'deny']);
    app(PermissionRegistrar::class)->flushUser($this->org->admin);

    $this->actingAs($this->org->admin)
        ->post('/leads/bulk-assign', [
            'lead_ids' => [$lead->id],
            'assigned_to' => $this->org->priya->id,
        ])
        ->assertForbidden();

    expect($lead->fresh()->assigned_to)->toBe($this->org->rahul->id);
});

test('the leads list offers bulk assign only to admin and super admin', function (string $who, bool $allowed) {
    $can = $this->actingAs($this->org->{$who})->get('/leads')->assertOk()->inertiaProps('can');

    expect($can['bulkAssign'])->toBe($allowed);
})->with([
    ['admin', true],
    ['super', true],
    ['manager', false],
    ['rahul', false],
]);
