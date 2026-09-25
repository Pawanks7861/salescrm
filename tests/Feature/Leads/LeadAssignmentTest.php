<?php

use App\Models\Lead;
use App\Models\LeadAssignment;

beforeEach(function () {
    $this->org = salesOrg();
    // Legacy team stamp: must not give the team manager any access.
    $this->unassigned = Lead::factory()->create(['assigned_to' => null, 'team_id' => $this->org->team->id]);
});

test('admin assigns an unassigned lead to a user', function () {
    $this->actingAs($this->org->admin)
        ->post("/leads/{$this->unassigned->id}/assign", ['assigned_to' => $this->org->rahul->id, 'reason' => 'Local area'])
        ->assertRedirect();

    $lead = $this->unassigned->fresh();
    expect($lead->assigned_to)->toBe($this->org->rahul->id);

    $history = LeadAssignment::where('lead_id', $lead->id)->sole();
    expect($history->from_user_id)->toBeNull()
        ->and($history->to_user_id)->toBe($this->org->rahul->id)
        ->and($history->assigned_by)->toBe($this->org->admin->id)
        ->and($history->to_team_id)->toBeNull()
        ->and($history->assignment_type->value)->toBe('manual')
        ->and($history->reason)->toBe('Local area');
});

test('a manager cannot assign an unassigned lead, even one stamped with their legacy team', function () {
    $this->actingAs($this->org->manager)
        ->post("/leads/{$this->unassigned->id}/assign", ['assigned_to' => $this->org->rahul->id])
        ->assertForbidden();

    expect($this->unassigned->fresh()->assigned_to)->toBeNull();
});

test('a manager with lead.reassign may hand off their own lead and then loses access', function () {
    $own = Lead::factory()->assignedTo($this->org->manager)->create();

    $this->actingAs($this->org->manager)
        ->post("/leads/{$own->id}/assign", ['assigned_to' => $this->org->outsider->id])
        ->assertRedirect();

    expect($own->fresh()->assigned_to)->toBe($this->org->outsider->id);
    $this->actingAs($this->org->manager)->get("/leads/{$own->id}")->assertForbidden();
});

test('a team_id in the request is ignored', function () {
    $this->actingAs($this->org->admin)
        ->post("/leads/{$this->unassigned->id}/assign", ['assigned_to' => $this->org->rahul->id, 'team_id' => $this->org->otherTeam->id])
        ->assertRedirect();

    expect(LeadAssignment::where('lead_id', $this->unassigned->id)->sole()->to_team_id)->toBeNull();
});

test('inactive users cannot receive leads', function () {
    $this->org->priya->forceFill(['is_active' => false])->save();

    $this->actingAs($this->org->admin)
        ->post("/leads/{$this->unassigned->id}/assign", ['assigned_to' => $this->org->priya->id])
        ->assertSessionHasErrors('assigned_to');
});

test('admin with view_all can assign to any active user', function () {
    $this->actingAs($this->org->admin)
        ->post("/leads/{$this->unassigned->id}/assign", ['assigned_to' => $this->org->outsider->id])
        ->assertRedirect();

    expect($this->unassigned->fresh()->assigned_to)->toBe($this->org->outsider->id);
});

test('sales executive can never assign themselves a lead via request manipulation', function () {
    $priyaLead = Lead::factory()->assignedTo($this->org->priya)->create();

    $this->actingAs($this->org->rahul)->post("/leads/{$this->unassigned->id}/assign", ['assigned_to' => $this->org->rahul->id])->assertForbidden();
    $this->actingAs($this->org->rahul)->post("/leads/{$priyaLead->id}/assign", ['assigned_to' => $this->org->rahul->id])->assertForbidden();
    $this->actingAs($this->org->rahul)->put("/leads/{$priyaLead->id}", leadPayload(['assigned_to' => $this->org->rahul->id]))->assertForbidden();

    expect($priyaLead->fresh()->assigned_to)->toBe($this->org->priya->id);
    expect(LeadAssignment::count())->toBe(0);
});

test('assignment audit is recorded', function () {
    $this->actingAs($this->org->admin)->post("/leads/{$this->unassigned->id}/assign", ['assigned_to' => $this->org->rahul->id]);

    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_ASSIGNED', 'entity_id' => $this->unassigned->id, 'user_id' => $this->org->admin->id]);
});
