<?php

use App\Enums\MeetingStatus;
use App\Models\Lead;
use App\Models\Meeting;
use App\Notifications\Meetings\MeetingActivityNotification;
use Carbon\CarbonImmutable;

/*
 * RELEASE-BLOCKING (§71). Rahul and Priya are executives on the same team.
 * Rahul must never read, change or discover Priya's meeting or, through it,
 * Priya's lead.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->leadA = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
    $this->leadB = Lead::factory()->assignedTo($this->org->priya)->create(['first_name' => 'Secret', 'last_name' => 'Customer', 'phone' => '9811122233']);
    $this->meetingA = scheduleMeeting($this->leadA, $this->org->rahul, ['title' => 'Rahul demo']);
    $this->meetingB = scheduleMeeting($this->leadB, $this->org->priya, ['title' => 'Priya confidential demo', 'agenda' => 'Private pricing notes', 'start_time' => '15:00', 'end_time' => '16:00']);
});

test('rahul cannot open meeting B by numeric id', function () {
    $this->actingAs($this->org->rahul)->get("/meetings/{$this->meetingB->id}")->assertForbidden();
    $this->actingAs($this->org->rahul)->get("/meetings/{$this->meetingA->id}")->assertOk();
});

test('rahul cannot edit, reschedule, cancel, complete, confirm, start, no-show, delete or restore meeting B', function () {
    $id = $this->meetingB->id;
    $rahul = $this->org->rahul;
    $tomorrow = CarbonImmutable::now('Asia/Kolkata')->addDays(2)->format('Y-m-d');

    $this->actingAs($rahul)->put("/meetings/{$id}", ['title' => 'hijacked'])->assertForbidden();
    $this->actingAs($rahul)->post("/meetings/{$id}/reschedule", ['scheduled_date' => $tomorrow, 'start_time' => '12:00', 'end_time' => '13:00'])->assertForbidden();
    $this->actingAs($rahul)->post("/meetings/{$id}/cancel", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($rahul)->post("/meetings/{$id}/complete", ['outcome' => 'interested', 'notes' => 'x'])->assertForbidden();
    $this->actingAs($rahul)->post("/meetings/{$id}/confirm")->assertForbidden();
    $this->actingAs($rahul)->post("/meetings/{$id}/start")->assertForbidden();
    $this->actingAs($rahul)->post("/meetings/{$id}/no-show")->assertForbidden();
    $this->actingAs($rahul)->delete("/meetings/{$id}")->assertForbidden();
    $this->actingAs($rahul)->post("/meetings/{$id}/restore")->assertForbidden();

    $fresh = $this->meetingB->fresh();
    expect($fresh->title)->toBe('Priya confidential demo')
        ->and($fresh->status)->toBe(MeetingStatus::Scheduled)
        ->and(Meeting::count())->toBe(2);
});

test('rahul cannot mark attendance, manage participants or RSVP on meeting B', function () {
    $id = $this->meetingB->id;
    $participant = $this->meetingB->participants()->forceCreate(['participant_type' => 'external', 'name' => 'Guest', 'attendance_status' => 'pending', 'invitation_status' => 'not_sent']);

    $this->actingAs($this->org->rahul)->post("/meetings/{$id}/participants", ['type' => 'user', 'user_id' => $this->org->rahul->id])->assertForbidden();
    $this->actingAs($this->org->rahul)->delete("/meetings/{$id}/participants/{$participant->id}")->assertForbidden();
    $this->actingAs($this->org->rahul)->post("/meetings/{$id}/respond", ['attendance_status' => 'confirmed'])->assertForbidden();
    $this->actingAs($this->org->rahul)->post("/meetings/{$id}/no-show", ['absent_participant_ids' => [$participant->id]])->assertForbidden();

    expect($participant->fresh())->not->toBeNull()
        ->and($this->meetingB->participants()->count())->toBe(1);
});

test('meeting B never appears in rahul\'s list, tabs, search or calendar', function () {
    foreach (['upcoming', 'today', 'past', 'completed', 'cancelled', 'no_show', 'all'] as $tab) {
        $ids = collect($this->actingAs($this->org->rahul)->get("/meetings?tab={$tab}")->inertiaProps('meetings.data'))->pluck('id');
        expect($ids)->not->toContain($this->meetingB->id);
    }

    foreach ([$this->meetingB->meeting_number, 'Priya confidential', 'Secret', '9811122233', $this->leadB->lead_number] as $term) {
        expect($this->actingAs($this->org->rahul)->get('/meetings?tab=all&search='.urlencode($term))->inertiaProps('meetings.data'))->toBeEmpty();
    }

    expect($this->actingAs($this->org->rahul)->get("/meetings?tab=all&lead={$this->leadB->id}")->inertiaProps('meetings.data'))->toBeEmpty()
        ->and($this->actingAs($this->org->rahul)->get("/meetings?tab=all&host={$this->org->priya->id}")->inertiaProps('meetings.data'))->toBeEmpty();

    $events = $this->actingAs($this->org->rahul)
        ->getJson('/calendar/events?start=2026-09-21T00:00:00&end=2026-09-28T00:00:00&host='.$this->org->priya->id)
        ->assertOk()->json('data');
    expect($events)->toBeEmpty();

    $events = $this->actingAs($this->org->rahul)->getJson('/calendar/events?start=2026-09-21T00:00:00&end=2026-09-28T00:00:00')->json('data');
    expect(collect($events)->pluck('id')->all())->toBe([(string) $this->meetingA->id]);
});

test('rahul cannot use the participant endpoint to reach lead B', function () {
    $this->actingAs($this->org->rahul)->getJson("/meetings/participants/search?lead_id={$this->leadB->id}")->assertNotFound();
    $this->actingAs($this->org->rahul)->post('/meetings', meetingPayload($this->leadB, ['start_time' => '12:00', 'end_time' => '13:00']))->assertForbidden();
    expect(Meeting::where('lead_id', $this->leadB->id)->count())->toBe(1);
});

test('rahul cannot retrieve lead B through meeting B or through a notification', function () {
    $this->actingAs($this->org->rahul)->get("/leads/{$this->leadB->id}")->assertForbidden();

    // A stale notification that references meeting B (e.g. Rahul was a participant once).
    $this->org->rahul->notify(new MeetingActivityNotification($this->meetingB, MeetingActivityNotification::SCHEDULED, 'Priya'));
    $rows = $this->actingAs($this->org->rahul)->getJson('/notifications/recent')->json('data');
    expect($rows[0]['stale'])->toBeTrue()
        ->and($rows[0]['target'])->toBeNull();

    $id = $this->org->rahul->notifications()->value('id');
    $this->actingAs($this->org->rahul)->get("/notifications/{$id}/open")->assertRedirect(route('notifications.index'));
});

test('meeting responses never leak priya\'s meeting or lead data to rahul', function () {
    $pages = [
        $this->actingAs($this->org->rahul)->get('/meetings?tab=all'),
        $this->actingAs($this->org->rahul)->get('/calendar'),
        $this->actingAs($this->org->rahul)->getJson('/calendar/events?start=2026-09-21T00:00:00&end=2026-09-28T00:00:00'),
        $this->actingAs($this->org->rahul)->get('/dashboard'),
        $this->actingAs($this->org->rahul)->get("/leads/{$this->leadA->id}"),
        $this->actingAs($this->org->rahul)->get("/meetings/{$this->meetingA->id}"),
        $this->actingAs($this->org->rahul)->getJson('/meetings/participants/search?q=a'),
        $this->actingAs($this->org->rahul)->getJson('/notifications/recent'),
    ];

    foreach ($pages as $response) {
        $body = $response->getContent();
        expect($body)->not->toContain('Secret Customer')
            ->and($body)->not->toContain('9811122233')
            ->and($body)->not->toContain('Priya confidential demo')
            ->and($body)->not->toContain('Private pricing notes')
            ->and($body)->not->toContain($this->meetingB->meeting_number);
    }
});

test('adding rahul as a participant never grants him priya\'s lead', function () {
    // Forced at the data layer: participant rows cannot bypass lead visibility.
    $this->meetingB->participants()->forceCreate(['participant_type' => 'user', 'user_id' => $this->org->rahul->id, 'name' => 'Rahul Sharma', 'attendance_status' => 'pending', 'invitation_status' => 'notified']);

    $this->actingAs($this->org->rahul)->get("/meetings/{$this->meetingB->id}")->assertForbidden();
    $this->actingAs($this->org->rahul)->get("/leads/{$this->leadB->id}")->assertForbidden();
    expect(collect($this->actingAs($this->org->rahul)->get('/meetings?tab=all')->inertiaProps('meetings.data'))->pluck('id'))->not->toContain($this->meetingB->id);
});

test('priya cannot be invited to a meeting on rahul\'s lead she cannot see', function () {
    $this->actingAs($this->org->rahul)
        ->post('/meetings', meetingPayload($this->leadA, ['start_time' => '12:00', 'end_time' => '13:00', 'participant_user_ids' => [$this->org->priya->id]]))
        ->assertSessionHasErrors('participant_user_ids.0');

    $this->actingAs($this->org->rahul)
        ->post("/meetings/{$this->meetingA->id}/participants", ['type' => 'user', 'user_id' => $this->org->priya->id])
        ->assertSessionHasErrors('user_id');
});

test('rahul cannot make priya the host by manipulating host_user_id', function () {
    $this->actingAs($this->org->rahul)
        ->post('/meetings', meetingPayload($this->leadA, ['start_time' => '12:00', 'end_time' => '13:00', 'host_user_id' => $this->org->priya->id]))
        ->assertSessionHasErrors('host_user_id');

    $this->actingAs($this->org->rahul)->put("/meetings/{$this->meetingA->id}", ['host_user_id' => $this->org->priya->id])->assertSessionHasErrors('host_user_id');
    expect($this->meetingA->fresh()->host_user_id)->toBe($this->org->rahul->id);
});

test('a legacy team manager sees no member meetings; admin and super admin see all', function () {
    $outsiderLead = Lead::factory()->assignedTo($this->org->outsider)->create();
    $outsiderMeeting = scheduleMeeting($outsiderLead, $this->org->outsider, ['start_time' => '12:00', 'end_time' => '13:00']);

    $managerIds = collect($this->actingAs($this->org->manager)->get('/meetings?tab=all')->inertiaProps('meetings.data'))->pluck('id');
    expect($managerIds->all())->toBe([]);
    foreach ([$this->meetingA, $this->meetingB, $outsiderMeeting] as $m) {
        $this->actingAs($this->org->manager)->get("/meetings/{$m->id}")->assertForbidden();
        $this->actingAs($this->org->manager)->post("/meetings/{$m->id}/cancel", ['reason' => 'x'])->assertForbidden();
    }

    $events = collect($this->actingAs($this->org->manager)->getJson('/calendar/events?start=2026-09-21T00:00:00&end=2026-09-28T00:00:00')->json('data'))->pluck('id');
    expect($events->all())->toBe([]);

    foreach ([$this->org->admin, $this->org->super] as $user) {
        $ids = collect($this->actingAs($user)->get('/meetings?tab=all')->inertiaProps('meetings.data'))->pluck('id');
        expect($ids)->toContain($this->meetingA->id, $this->meetingB->id, $outsiderMeeting->id);
        $this->actingAs($user)->get("/meetings/{$outsiderMeeting->id}")->assertOk();
    }
});

test('a meeting stays hidden from its host when the lead moves out of their visibility', function () {
    $this->leadA->forceFill(['assigned_to' => $this->org->priya->id])->save();

    $this->actingAs($this->org->rahul)->get("/meetings/{$this->meetingA->id}")->assertForbidden();
    expect(collect($this->actingAs($this->org->rahul)->get('/meetings?tab=all')->inertiaProps('meetings.data'))->pluck('id'))->not->toContain($this->meetingA->id);
});

test('unknown meeting ids return 404 and there is no export route', function () {
    $this->actingAs($this->org->admin)->get('/meetings/999999')->assertNotFound();
    $this->actingAs($this->org->admin)->get('/meetings/export')->assertNotFound();
    $this->actingAs($this->org->admin)->get('/calendar/export')->assertNotFound();
    $this->actingAs($this->org->admin)->get('/calendar.ics')->assertNotFound();
});
