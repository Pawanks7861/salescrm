<?php

use App\Enums\FollowupStatus;
use App\Models\Activity;
use App\Models\Followup;
use App\Models\Lead;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->org = salesOrg();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Kolkata'));
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->followup = scheduleFollowup($this->lead, $this->org->rahul, [
        'scheduled_date' => '2026-09-23', 'scheduled_time' => '12:00', 'priority' => 'high', 'title' => 'Pricing call', 'reminder_minutes' => 30,
    ]);
});

test('rescheduling keeps the original as history and creates a linked pending follow-up', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/reschedule", [
        'scheduled_date' => '2026-09-24', 'scheduled_time' => '15:00', 'reason' => 'Customer in a meeting',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $old = $this->followup->fresh();
    $new = Followup::where('rescheduled_from_id', $old->id)->sole();

    expect($old->status)->toBe(FollowupStatus::Rescheduled)
        ->and($old->reschedule_reason)->toBe('Customer in a meeting')
        ->and($old->scheduled_at->utc()->toDateTimeString())->toBe('2026-09-23 06:30:00')
        ->and($new->status)->toBe(FollowupStatus::Pending)
        ->and($new->scheduled_at->utc()->toDateTimeString())->toBe('2026-09-24 09:30:00')
        ->and($new->title)->toBe('Pricing call')
        ->and($new->priority->value)->toBe('high')
        ->and($new->reminder_minutes_before)->toBe(30)
        ->and($new->assigned_to)->toBe($old->assigned_to)
        ->and($new->followup_type_id)->toBe($old->followup_type_id)
        ->and($new->created_by)->toBe($this->org->rahul->id);

    expect(Activity::where('subject_id', $this->lead->id)->where('type', 'followup_rescheduled')->sole()->description)
        ->toBe('Rescheduled the Call follow-up from Sep 23, 12:00 PM to Sep 24, 3:00 PM. Reason: Customer in a meeting');
});

test('rescheduling to the same time or into the past is rejected', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/reschedule", ['scheduled_date' => '2026-09-23', 'scheduled_time' => '12:00'])
        ->assertSessionHasErrors('scheduled_time');
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/reschedule", ['scheduled_date' => '2026-09-22', 'scheduled_time' => '12:00'])
        ->assertSessionHasErrors('scheduled_time');
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/reschedule", [])
        ->assertSessionHasErrors(['scheduled_date', 'scheduled_time']);

    expect(Followup::count())->toBe(1)->and($this->followup->fresh()->isPending())->toBeTrue();
});

test('an overdue follow-up can be rescheduled and is no longer overdue', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 13:00', 'Asia/Kolkata'));
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/reschedule", ['scheduled_date' => '2026-09-24', 'scheduled_time' => '10:00'])
        ->assertSessionHasNoErrors();

    $counts = $this->actingAs($this->org->rahul)->get('/follow-ups')->inertiaProps('counts');
    expect($counts['overdue'])->toBe(0)->and($counts['upcoming'])->toBe(1);
});

test('a rescheduled record cannot be rescheduled again; the chain is shown on the detail page', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/reschedule", ['scheduled_date' => '2026-09-24', 'scheduled_time' => '10:00']);
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/reschedule", ['scheduled_date' => '2026-09-25', 'scheduled_time' => '10:00'])
        ->assertForbidden();

    $new = Followup::where('rescheduled_from_id', $this->followup->id)->sole();
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$new->id}/reschedule", ['scheduled_date' => '2026-09-25', 'scheduled_time' => '10:00'])
        ->assertSessionHasNoErrors();

    $latest = Followup::where('status', 'pending')->sole();
    $history = $this->actingAs($this->org->rahul)->get("/follow-ups/{$latest->id}")->inertiaProps('history');
    expect(collect($history)->pluck('id')->all())->toContain($this->followup->id, $new->id);
});
