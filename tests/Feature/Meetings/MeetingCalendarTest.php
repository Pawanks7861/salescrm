<?php

use App\Models\Lead;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
});

function calendarEvents(object $test, $user, string $query = ''): array
{
    return $test->actingAs($user)->getJson('/calendar/events?start=2026-09-21T00:00:00&end=2026-09-28T00:00:00'.$query)->assertOk()->json('data');
}

test('the feed returns visible meetings in the range as CRM wall-clock times', function () {
    $m = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25', 'start_time' => '10:00', 'end_time' => '11:30']);
    scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-10-05', 'start_time' => '10:00', 'end_time' => '11:00']);

    $events = calendarEvents($this, $this->org->rahul);
    expect($events)->toHaveCount(1)
        ->and($events[0]['id'])->toBe((string) $m->id)
        ->and($events[0]['start'])->toBe('2026-09-25T10:00:00')
        ->and($events[0]['end'])->toBe('2026-09-25T11:30:00')
        ->and($events[0]['editable'])->toBeTrue()
        ->and($events[0]['extendedProps']['lead'])->toBe('Amit Desai')
        ->and($events[0]['extendedProps']['color'])->toBe('pink')
        ->and($events[0]['extendedProps']['url'])->toBe("/meetings/{$m->id}");
});

test('meetings overlapping the range edges are included; rescheduled history is not', function () {
    $m = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25', 'start_time' => '10:00', 'end_time' => '11:00']);
    $this->actingAs($this->org->rahul)->post("/meetings/{$m->id}/reschedule", ['scheduled_date' => '2026-09-26', 'start_time' => '10:00', 'end_time' => '11:00'])->assertSessionHasNoErrors();

    $events = calendarEvents($this, $this->org->rahul);
    expect($events)->toHaveCount(1)
        ->and($events[0]['start'])->toBe('2026-09-26T10:00:00');
});

test('cancelled meetings can be hidden and filters apply server-side', function () {
    $keep = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25', 'start_time' => '10:00', 'end_time' => '11:00']);
    $gone = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25', 'start_time' => '12:00', 'end_time' => '13:00', 'meeting_type_id' => meetingTypeId('site_visit')]);
    $this->actingAs($this->org->rahul)->post("/meetings/{$gone->id}/cancel", ['reason' => 'x']);

    expect(calendarEvents($this, $this->org->rahul))->toHaveCount(2)
        ->and(collect(calendarEvents($this, $this->org->rahul, '&hide_cancelled=1'))->pluck('id')->all())->toBe([(string) $keep->id])
        ->and(collect(calendarEvents($this, $this->org->rahul, '&type='.meetingTypeId('site_visit')))->pluck('id')->all())->toBe([(string) $gone->id]);
});

test('a legacy team manager sees only their own meetings; admin mine-only scope narrows to their own', function () {
    scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25']);
    $own = scheduleMeeting(null, $this->org->manager, ['scheduled_date' => '2026-09-25', 'start_time' => '14:00', 'end_time' => '15:00']);
    $adminOwn = scheduleMeeting(null, $this->org->admin, ['scheduled_date' => '2026-09-25', 'start_time' => '16:00', 'end_time' => '17:00']);

    expect(collect(calendarEvents($this, $this->org->manager))->pluck('id')->all())->toBe([(string) $own->id])
        ->and(calendarEvents($this, $this->org->admin))->toHaveCount(3)
        ->and(collect(calendarEvents($this, $this->org->admin, '&scope=mine'))->pluck('id')->all())->toBe([(string) $adminOwn->id]);
});

test('the range is validated and capped', function () {
    $this->actingAs($this->org->rahul)->getJson('/calendar/events?start=2026-09-28&end=2026-09-21')->assertStatus(422);
    $this->actingAs($this->org->rahul)->getJson('/calendar/events?start=2026-01-01&end=2026-06-01')->assertStatus(422);
    $this->actingAs($this->org->rahul)->getJson('/calendar/events')->assertStatus(422);
});

test('non-editable events for meetings the viewer cannot manage', function () {
    $internal = scheduleMeeting(null, $this->org->manager, ['scheduled_date' => '2026-09-25', 'participant_user_ids' => [$this->org->rahul->id]]);

    $events = calendarEvents($this, $this->org->rahul);
    expect($events[0]['id'])->toBe((string) $internal->id)
        ->and($events[0]['editable'])->toBeFalse();
});

test('the calendar page renders with form options', function () {
    $props = $this->actingAs($this->org->rahul)->get('/calendar')->assertOk()->inertiaProps();
    expect($props['can']['create'])->toBeTrue()
        ->and($props['can']['createWithoutLead'])->toBeFalse()
        ->and($props['options']['types'])->not->toBeEmpty();
});
