<?php

use App\Models\Lead;
use App\Models\Meeting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * §78 N+1 guards: query counts must not grow with the number of meetings.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 07:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->others = Lead::factory()->count(3)->assignedTo($this->org->rahul)->create();
    $this->seeded = 0;
});

/** Non-overlapping meetings today and tomorrow, mixing leads, types, participants and hosts. */
function seedMeetings(object $test, int $count): void
{
    $types = ['product_demo', 'site_visit', 'zoom', 'phone_call'];
    for ($i = 0; $i < $count; $i++) {
        $slot = $test->seeded++;
        $day = CarbonImmutable::parse('2026-09-24', 'Asia/Kolkata')->addDays(intdiv($slot, 48));
        $start = $day->setTime(0, 0)->addMinutes(($slot % 48) * 30 + 7 * 60);
        $lead = $slot % 4 === 0 ? $test->lead : $test->others[$slot % 3];

        scheduleMeeting($lead, $slot % 2 ? $test->org->admin : $test->org->rahul, [
            'host_user_id' => $test->org->rahul->id,
            'meeting_type_id' => meetingTypeId($types[$slot % 4]),
            'scheduled_date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->addMinutes(25)->format('H:i'),
            'end_date' => $start->addMinutes(25)->format('Y-m-d'),
            'include_lead' => $slot % 3 === 0,
            'external_participants' => $slot % 5 === 0 ? [['name' => "Guest {$slot}"]] : [],
            'participant_user_ids' => $slot % 2 ? [$test->org->admin->id] : [],
        ], true);
    }
}

function meetingQueries(callable $callback): int
{
    $callback(); // warm-up (settings/permission caches)
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('meeting list query count does not grow with rows', function () {
    seedMeetings($this, 10);
    $small = meetingQueries(fn () => $this->actingAs($this->org->admin)->get('/meetings?tab=all&per_page=100')->assertOk());

    seedMeetings($this, 30);
    $large = meetingQueries(fn () => $this->actingAs($this->org->admin)->get('/meetings?tab=all&per_page=100')->assertOk());

    expect($large)->toBe($small);
});

test('calendar feed query count does not grow with events', function () {
    $url = '/calendar/events?start=2026-09-21T00:00:00&end=2026-10-05T00:00:00';
    seedMeetings($this, 10);
    $small = meetingQueries(fn () => $this->actingAs($this->org->admin)->getJson($url)->assertOk());

    seedMeetings($this, 30);
    $large = meetingQueries(fn () => $this->actingAs($this->org->admin)->getJson($url)->assertOk());

    expect($large)->toBe($small);
});

test('lead 360 query count does not grow with meetings', function () {
    seedMeetings($this, 8);
    $small = meetingQueries(fn () => $this->actingAs($this->org->admin)->get("/leads/{$this->lead->id}")->assertOk());

    seedMeetings($this, 32);
    $large = meetingQueries(fn () => $this->actingAs($this->org->admin)->get("/leads/{$this->lead->id}")->assertOk());

    expect($large)->toBe($small);
});

test('dashboard query count does not grow with meetings', function () {
    seedMeetings($this, 10);
    $small = meetingQueries(fn () => $this->actingAs($this->org->admin)->get('/dashboard')->assertOk());

    seedMeetings($this, 30);
    $large = meetingQueries(fn () => $this->actingAs($this->org->admin)->get('/dashboard')->assertOk());

    expect($large)->toBe($small);
});

test('meeting detail query count does not grow with participants', function () {
    seedMeetings($this, 1);
    $meeting = Meeting::first();
    $small = meetingQueries(fn () => $this->actingAs($this->org->admin)->get("/meetings/{$meeting->id}")->assertOk());

    for ($i = 0; $i < 15; $i++) {
        $meeting->participants()->create(['participant_type' => 'external', 'name' => "Extra {$i}", 'attendance_status' => 'pending', 'invitation_status' => 'not_sent']);
    }
    $large = meetingQueries(fn () => $this->actingAs($this->org->admin)->get("/meetings/{$meeting->id}")->assertOk());

    expect($large)->toBe($small);
});

test('notification endpoints query count does not grow with meeting notifications', function () {
    seedMeetings($this, 10); // admin-created rows notify Rahul (host)
    $small = meetingQueries(fn () => $this->actingAs($this->org->rahul)->getJson('/notifications/recent')->assertOk());
    $smallIndex = meetingQueries(fn () => $this->actingAs($this->org->rahul)->get('/notifications')->assertOk());

    seedMeetings($this, 30);
    expect($this->org->rahul->notifications()->count())->toBeGreaterThan(15)
        ->and(meetingQueries(fn () => $this->actingAs($this->org->rahul)->getJson('/notifications/recent')->assertOk()))->toBe($small)
        ->and(meetingQueries(fn () => $this->actingAs($this->org->rahul)->get('/notifications')->assertOk()))->toBe($smallIndex);
});
