<?php

use App\Enums\FollowupStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Services\SettingService;

beforeEach(function () {
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->followup = scheduleFollowup($this->lead, $this->org->rahul);
});

test('cancelling records the reason, canceller and timeline entry', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/cancel", ['reason' => 'Lead asked to stop calls'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $f = $this->followup->fresh();
    expect($f->status)->toBe(FollowupStatus::Cancelled)
        ->and($f->cancellation_reason)->toBe('Lead asked to stop calls')
        ->and($f->cancelled_by)->toBe($this->org->rahul->id)
        ->and($f->cancelled_at)->not->toBeNull();

    expect(Activity::where('subject_id', $this->lead->id)->where('type', 'followup_cancelled')->sole()->description)
        ->toBe('Cancelled the Call follow-up. Reason: Lead asked to stop calls');
});

test('a reason is required by default and optional when the setting is off', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/cancel", ['reason' => '   '])
        ->assertSessionHasErrors('reason');
    expect($this->followup->fresh()->isPending())->toBeTrue();

    app(SettingService::class)->updateGroup('followup', ['followup.require_cancellation_reason' => false]);
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/cancel")->assertSessionHasNoErrors();
    expect($this->followup->fresh()->status)->toBe(FollowupStatus::Cancelled);
});

test('cancelled follow-ups are kept and listed under the cancelled tab, not deleted', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/cancel", ['reason' => 'x']);

    $ids = collect($this->actingAs($this->org->rahul)->get('/follow-ups?tab=cancelled')->inertiaProps('followups.data'))->pluck('id');
    expect($ids->all())->toBe([$this->followup->id]);
    expect($this->actingAs($this->org->rahul)->get('/follow-ups')->inertiaProps('followups.data'))->toBeEmpty();
});

test('cancelling does not change the lead status', function () {
    $before = $this->lead->fresh()->status_id;
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/cancel", ['reason' => 'x']);

    expect($this->lead->fresh()->status_id)->toBe($before);
});
