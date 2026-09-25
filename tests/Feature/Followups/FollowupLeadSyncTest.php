<?php

use App\Models\Followup;
use App\Models\Lead;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->org = salesOrg();
    // Wed 23 Sep 2026, 10:00 IST
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Kolkata'));
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

test('next_followup_at follows the exact create / complete / cancel / reschedule scenario', function () {
    $rahul = $this->org->rahul;
    $as = fn () => $this->actingAs($rahul);

    $as()->post('/follow-ups', followupPayload($this->lead, crmSlot(ist('2026-09-25 11:00'))))->assertSessionHasNoErrors();
    $as()->post('/follow-ups', followupPayload($this->lead, crmSlot(ist('2026-09-27 11:00'))))->assertSessionHasNoErrors();
    expect(nextFollowup($this->lead))->toBe(ist('2026-09-25 11:00')->toDateTimeString());

    $sep25 = Followup::where('scheduled_at', ist('2026-09-25 11:00'))->sole();
    $as()->post("/follow-ups/{$sep25->id}/complete", ['outcome' => 'connected'])->assertSessionHasNoErrors();
    expect(nextFollowup($this->lead))->toBe(ist('2026-09-27 11:00')->toDateTimeString());

    $sep27 = Followup::where('scheduled_at', ist('2026-09-27 11:00'))->sole();
    $as()->post("/follow-ups/{$sep27->id}/cancel", ['reason' => 'Customer travelling'])->assertSessionHasNoErrors();
    expect(nextFollowup($this->lead))->toBeNull();

    $as()->post('/follow-ups', followupPayload($this->lead, crmSlot(ist('2026-09-24 11:00'))))->assertSessionHasNoErrors();
    expect(nextFollowup($this->lead))->toBe(ist('2026-09-24 11:00')->toDateTimeString());

    $sep24 = Followup::where('scheduled_at', ist('2026-09-24 11:00'))->sole();
    $as()->post("/follow-ups/{$sep24->id}/reschedule", crmSlot(ist('2026-09-28 11:00')))->assertSessionHasNoErrors();
    expect(nextFollowup($this->lead))->toBe(ist('2026-09-28 11:00')->toDateTimeString());
});

test('an overdue pending follow-up keeps next_followup_at in the past (option A)', function () {
    scheduleFollowup($this->lead, $this->org->rahul, crmSlot(ist('2026-09-23 12:00')));
    $this->travelTo(CarbonImmutable::parse('2026-09-23 15:00', 'Asia/Kolkata'));

    expect(nextFollowup($this->lead))->toBe(ist('2026-09-23 12:00')->toDateTimeString());
    expect($this->lead->fresh()->next_followup_at->isPast())->toBeTrue();
});

test('delete and restore resync next_followup_at; the sync never touches leads.updated_at', function () {
    $updatedAt = $this->lead->fresh()->updated_at;
    $this->travel(5)->minutes();

    $f = scheduleFollowup($this->lead, $this->org->rahul, crmSlot(ist('2026-09-26 11:00')));
    expect(nextFollowup($this->lead))->not->toBeNull()
        ->and($this->lead->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();

    $this->actingAs($this->org->admin)->delete("/follow-ups/{$f->id}")->assertRedirect();
    expect(nextFollowup($this->lead))->toBeNull();

    $this->actingAs($this->org->admin)->post("/follow-ups/{$f->id}/restore")->assertRedirect();
    expect(nextFollowup($this->lead))->toBe(ist('2026-09-26 11:00')->toDateTimeString());
});

test('completion with schedule-next moves next_followup_at to the new follow-up', function () {
    $f = scheduleFollowup($this->lead, $this->org->rahul, crmSlot(ist('2026-09-24 11:00')));

    $this->actingAs($this->org->rahul)->post("/follow-ups/{$f->id}/complete", [
        'outcome' => 'interested',
        'schedule_next' => true,
        'next' => ['followup_type_id' => followupTypeId('whatsapp'), ...crmSlot(ist('2026-09-30 16:00'))],
    ])->assertSessionHasNoErrors();

    expect(nextFollowup($this->lead))->toBe(ist('2026-09-30 16:00')->toDateTimeString());
});
