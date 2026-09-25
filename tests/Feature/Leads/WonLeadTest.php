<?php

use App\Models\Lead;

beforeEach(function () {
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

test('winning a lead sets converted_at', function () {
    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/status", ['status_id' => leadStatusId('won')])->assertRedirect();

    $lead = $this->lead->fresh();
    expect($lead->converted_at)->not->toBeNull()->and($lead->lost_at)->toBeNull();
    $this->assertDatabaseHas('activities', ['subject_id' => $lead->id, 'type' => 'lead_won']);
});

test('reopening a won lead clears converted_at and records a reopen activity', function () {
    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/status", ['status_id' => leadStatusId('won')]);
    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/status", ['status_id' => leadStatusId('negotiation')]);

    $lead = $this->lead->fresh();
    expect($lead->converted_at)->toBeNull();
    $this->assertDatabaseHas('activities', ['subject_id' => $lead->id, 'type' => 'lead_reopened']);
});

test('won leads cannot be created directly through the form', function () {
    $this->actingAs($this->org->rahul)
        ->post('/leads', leadPayload(['status_id' => leadStatusId('won')]))
        ->assertSessionHasErrors('status_id');
});
