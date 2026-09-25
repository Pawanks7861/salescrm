<?php

use App\Models\Lead;
use App\Models\LostReason;

beforeEach(function () {
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

test('moving to lost requires a reason', function () {
    $this->actingAs($this->org->rahul)
        ->post("/leads/{$this->lead->id}/status", ['status_id' => leadStatusId('lost')])
        ->assertSessionHasErrors('lost_reason_id');

    expect($this->lead->fresh()->status->slug)->toBe('new');
});

test('lost with reason stores timestamp, reason and notes', function () {
    $reason = LostReason::first();

    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/status", [
        'status_id' => leadStatusId('lost'), 'lost_reason_id' => $reason->id, 'lost_reason_notes' => 'Went with competitor',
    ])->assertRedirect();

    $lead = $this->lead->fresh();
    expect($lead->lost_at)->not->toBeNull()
        ->and($lead->lost_reason_id)->toBe($reason->id)
        ->and($lead->lost_reason_notes)->toBe('Went with competitor');
    $this->assertDatabaseHas('activities', ['subject_id' => $lead->id, 'type' => 'lead_lost']);
});

test('reopening a lost lead clears current lost state but keeps history', function () {
    $reason = LostReason::first();
    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/status", ['status_id' => leadStatusId('lost'), 'lost_reason_id' => $reason->id]);
    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/status", ['status_id' => leadStatusId('contacted')])->assertRedirect();

    $lead = $this->lead->fresh();
    expect($lead->lost_at)->toBeNull()->and($lead->lost_reason_id)->toBeNull();

    $reopen = $lead->activities()->where('type', 'lead_reopened')->sole();
    expect($reopen->properties['previous_lost_reason'])->toBe($reason->name);
});

test('inactive lost reasons are rejected', function () {
    $reason = LostReason::first();
    $reason->forceFill(['is_active' => false])->save();

    $this->actingAs($this->org->rahul)
        ->post("/leads/{$this->lead->id}/status", ['status_id' => leadStatusId('lost'), 'lost_reason_id' => $reason->id])
        ->assertSessionHasErrors('lost_reason_id');
});
