<?php

use App\Models\Lead;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

test('the lead 360 meetings tab lists visible meetings with a schedule action', function () {
    $a = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25']);
    $b = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-26']);
    $this->actingAs($this->org->rahul)->post("/meetings/{$b->id}/cancel", ['reason' => 'x']);

    $props = $this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}")->assertOk()->inertiaProps();

    expect(collect($props['meetings'])->pluck('id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all())
        ->and($props['counts']['meetings'])->toBe(1)
        ->and($props['can']['createMeeting'])->toBeTrue()
        ->and($props['meetingForm']['default_host'])->toBe($this->org->rahul->id);
});

test('an admin sees the lead\'s meetings and defaults the host to the lead owner', function () {
    scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25']);

    $props = $this->actingAs($this->org->admin)->get("/leads/{$this->lead->id}")->inertiaProps();
    expect($props['meetings'])->toHaveCount(1)
        ->and($props['meetingForm']['default_host'])->toBe($this->org->rahul->id);

    $this->actingAs($this->org->manager)->get("/leads/{$this->lead->id}")->assertForbidden();
});

test('archived leads show history but offer no schedule action', function () {
    scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25']);
    $this->lead->delete();

    $props = $this->actingAs($this->org->admin)->get("/leads/{$this->lead->id}")->inertiaProps();
    expect($props['can']['createMeeting'])->toBeFalse();
});
