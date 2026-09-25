<?php

use App\Models\Lead;
use App\Models\Meeting;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

test('times are entered in the CRM timezone and stored in UTC', function () {
    scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25', 'start_time' => '10:00', 'end_time' => '11:00']);

    $raw = Meeting::query()->toBase()->first(['start_at', 'end_at', 'timezone']);
    expect(substr($raw->start_at, 0, 16))->toBe('2026-09-25 04:30')
        ->and(substr($raw->end_at, 0, 16))->toBe('2026-09-25 05:30')
        ->and($raw->timezone)->toBe('Asia/Kolkata');
});

test('a meeting entered in another timezone is converted correctly', function () {
    scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25', 'start_time' => '09:00', 'end_time' => '10:00', 'timezone' => 'Asia/Dubai']);

    $m = Meeting::sole();
    expect($m->start_at->equalTo(ist('2026-09-25 10:30')))->toBeTrue()
        ->and($m->timezone)->toBe('Asia/Dubai');
});

test('meetings may cross midnight with an end date', function () {
    scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25', 'start_time' => '23:00', 'end_date' => '2026-09-26', 'end_time' => '00:30']);

    $m = Meeting::sole();
    expect($m->durationMinutes())->toBe(90);
});

test('an invalid local time (DST gap) is rejected rather than silently shifted', function () {
    // 02:30 does not exist in New York on 2027-03-14.
    $this->actingAs($this->org->rahul)
        ->post('/meetings', meetingPayload($this->lead, ['scheduled_date' => '2027-03-14', 'start_time' => '02:30', 'end_time' => '03:30', 'timezone' => 'America/New_York']))
        ->assertSessionHasErrors('start_time');
});

test('today\'s meetings follow the CRM day, not the UTC day', function () {
    // 00:30 IST on Sep 25 is still Sep 24 in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-09-24 23:00', 'Asia/Kolkata'));
    scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25', 'start_time' => '00:30', 'end_time' => '01:00']);

    expect($this->actingAs($this->org->rahul)->get('/dashboard')->inertiaProps('meetings.counts.today'))->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-09-25 00:05', 'Asia/Kolkata'));
    expect($this->actingAs($this->org->rahul)->get('/dashboard')->inertiaProps('meetings.counts.today'))->toBe(1);
});
