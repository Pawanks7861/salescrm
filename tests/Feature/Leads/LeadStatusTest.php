<?php

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Permission;
use App\Services\PermissionRegistrar;

beforeEach(function () {
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

test('owner changes status; activity and audit are written', function () {
    $this->actingAs($this->org->rahul)
        ->post("/leads/{$this->lead->id}/status", ['status_id' => leadStatusId('contacted')])
        ->assertRedirect();

    expect($this->lead->fresh()->status->slug)->toBe('contacted');
    $this->assertDatabaseHas('activities', ['subject_id' => $this->lead->id, 'type' => 'status_changed']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_STATUS_CHANGED', 'entity_id' => $this->lead->id]);
});

test('inactive or unknown statuses are rejected', function () {
    $status = LeadStatus::where('slug', 'interested')->first();
    $status->forceFill(['is_active' => false])->save();

    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/status", ['status_id' => $status->id])->assertSessionHasErrors('status_id');
    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/status", ['status_id' => 99999])->assertSessionHasErrors('status_id');
});

test('a user without lead.change_status cannot change status', function () {
    $deny = Permission::where('name', 'lead.change_status')->first();
    $this->org->rahul->permissionOverrides()->attach($deny->id, ['type' => 'deny']);
    app(PermissionRegistrar::class)->flushUser($this->org->rahul);

    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/status", ['status_id' => leadStatusId('contacted')])->assertForbidden();
});

test('priority quick change is audited separately', function () {
    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/priority", ['priority' => 'urgent'])->assertRedirect();

    expect($this->lead->fresh()->priority->value)->toBe('urgent');
    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_PRIORITY_CHANGED', 'entity_id' => $this->lead->id]);
});
