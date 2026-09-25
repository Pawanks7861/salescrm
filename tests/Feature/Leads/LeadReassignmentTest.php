<?php

use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\Permission;
use App\Services\PermissionRegistrar;

beforeEach(function () {
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

test('admin reassigns and history keeps from/to', function () {
    $this->actingAs($this->org->admin)
        ->post("/leads/{$this->lead->id}/assign", ['assigned_to' => $this->org->priya->id])
        ->assertRedirect();

    expect($this->lead->fresh()->assigned_to)->toBe($this->org->priya->id);

    $history = LeadAssignment::where('lead_id', $this->lead->id)->sole();
    expect($history->from_user_id)->toBe($this->org->rahul->id)->and($history->to_user_id)->toBe($this->org->priya->id);

    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_REASSIGNED', 'entity_id' => $this->lead->id]);
    $this->assertDatabaseHas('activities', ['subject_id' => $this->lead->id, 'type' => 'lead_assigned']);
});

test('previous owner loses access after reassignment', function () {
    $this->actingAs($this->org->admin)->post("/leads/{$this->lead->id}/assign", ['assigned_to' => $this->org->priya->id]);

    $this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}")->assertForbidden();
    $this->actingAs($this->org->priya)->get("/leads/{$this->lead->id}")->assertOk();
});

test('reassigning an owned lead requires lead.reassign, not just lead.assign', function () {
    $deny = Permission::where('name', 'lead.reassign')->first();
    $this->org->admin->permissionOverrides()->attach($deny->id, ['type' => 'deny']);
    app(PermissionRegistrar::class)->flushUser($this->org->admin);

    $this->actingAs($this->org->admin)
        ->post("/leads/{$this->lead->id}/assign", ['assigned_to' => $this->org->priya->id])
        ->assertForbidden();
});

test('the legacy team manager cannot reassign a member lead', function () {
    foreach ([$this->org->manager, $this->org->otherManager] as $manager) {
        $this->actingAs($manager)
            ->post("/leads/{$this->lead->id}/assign", ['assigned_to' => $this->org->priya->id])
            ->assertForbidden();
    }

    expect($this->lead->fresh()->assigned_to)->toBe($this->org->rahul->id);
});

test('reassigning to the current owner is a no-op', function () {
    $this->actingAs($this->org->admin)->post("/leads/{$this->lead->id}/assign", ['assigned_to' => $this->org->rahul->id])->assertRedirect();

    expect(LeadAssignment::count())->toBe(0);
});
