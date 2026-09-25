<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Meeting;
use App\Services\SettingService;
use Carbon\CarbonImmutable;

/*
 * RELEASE-BLOCKING (§72). Overlap = existing.start < new.end AND existing.end > new.start.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 08:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
    $this->other = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->existing = scheduleMeeting($this->lead, $this->org->rahul, ['title' => 'Existing demo', 'scheduled_date' => '2026-09-25', 'start_time' => '10:00', 'end_time' => '11:00']);
});

function conflictPost(object $test, array $overrides)
{
    return $test->actingAs($test->org->rahul)->post('/meetings', meetingPayload($test->other, ['scheduled_date' => '2026-09-25', ...$overrides]));
}

test('10:00–11:00 blocks 10:30–11:30 with a descriptive message for a viewer who can see it', function () {
    conflictPost($this, ['start_time' => '10:30', 'end_time' => '11:30'])
        ->assertSessionHasErrors(['conflicts.0' => "Rahul Sharma already has another meeting from 10:00 AM to 11:00 AM on Sep 25 ({$this->existing->meeting_number}: Existing demo)."]);

    expect(Meeting::count())->toBe(1);
});

test('fully containing, contained and identical ranges conflict', function (string $start, string $end) {
    conflictPost($this, ['start_time' => $start, 'end_time' => $end])->assertSessionHasErrors('conflicts.0');
})->with([
    'identical' => ['10:00', '11:00'],
    'contained' => ['10:15', '10:45'],
    'containing' => ['09:30', '11:30'],
    'overlapping start' => ['09:30', '10:01'],
]);

test('touching edges 11:00–12:00 and 09:00–10:00 do not conflict', function () {
    conflictPost($this, ['start_time' => '11:00', 'end_time' => '12:00'])->assertSessionHasNoErrors();
    conflictPost($this, ['start_time' => '09:00', 'end_time' => '10:00'])->assertSessionHasNoErrors();

    expect(Meeting::count())->toBe(3);
});

test('cancelled meetings do not block', function () {
    $this->actingAs($this->org->rahul)->post("/meetings/{$this->existing->id}/cancel", ['reason' => 'Client busy'])->assertSessionHasNoErrors();

    conflictPost($this, ['start_time' => '10:30', 'end_time' => '11:30'])->assertSessionHasNoErrors();
});

test('the rescheduled original does not block its old slot, and a meeting never conflicts with itself when rescheduled', function () {
    $this->actingAs($this->org->rahul)
        ->post("/meetings/{$this->existing->id}/reschedule", ['scheduled_date' => '2026-09-25', 'start_time' => '10:30', 'end_time' => '11:30'])
        ->assertSessionHasNoErrors();

    conflictPost($this, ['start_time' => '09:00', 'end_time' => '10:30'])->assertSessionHasNoErrors();
    conflictPost($this, ['start_time' => '11:00', 'end_time' => '12:00'])->assertSessionHasErrors('conflicts.0');
});

test('internal participants are checked too', function () {
    // The manager hosts an internal meeting with Rahul as participant, 14:00–15:00.
    $internal = scheduleMeeting(null, $this->org->manager, ['title' => 'Pipeline review', 'scheduled_date' => '2026-09-25', 'start_time' => '14:00', 'end_time' => '15:00', 'participant_user_ids' => [$this->org->rahul->id]]);

    conflictPost($this, ['start_time' => '14:30', 'end_time' => '15:30'])
        ->assertSessionHasErrors(['conflicts.0' => "Rahul Sharma already has another meeting from 2:00 PM to 3:00 PM on Sep 25 ({$internal->meeting_number}: Pipeline review)."]);

    // …and inviting a busy participant is blocked as well.
    $this->actingAs($this->org->rahul)
        ->post('/meetings', meetingPayload($this->other, ['scheduled_date' => '2026-09-25', 'start_time' => '16:00', 'end_time' => '17:00', 'participant_user_ids' => [$this->org->admin->id]]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->org->admin)
        ->post('/meetings', meetingPayload(null, ['scheduled_date' => '2026-09-25', 'start_time' => '16:30', 'end_time' => '17:30', 'title' => 'x']))
        ->assertSessionHasErrors('conflicts.0');
});

test('a participant who declined is not busy', function () {
    $internal = scheduleMeeting(null, $this->org->manager, ['scheduled_date' => '2026-09-25', 'start_time' => '14:00', 'end_time' => '15:00', 'participant_user_ids' => [$this->org->rahul->id]]);
    $this->actingAs($this->org->rahul)->post("/meetings/{$internal->id}/respond", ['attendance_status' => 'declined'])->assertSessionHasNoErrors();

    conflictPost($this, ['start_time' => '14:30', 'end_time' => '15:30'])->assertSessionHasNoErrors();
});

test('users without override permission cannot bypass conflicts', function () {
    conflictPost($this, ['start_time' => '10:30', 'end_time' => '11:30', 'override_conflict' => true])
        ->assertSessionHasErrors('conflicts.0')
        ->assertSessionDoesntHaveErrors('conflict_override');

    expect(Meeting::count())->toBe(1);
});

test('a manager with override permission must confirm, and the override is audited', function () {
    // Lead access comes from an explicit company-wide grant, never from the legacy team.
    $this->org->manager = setPermission($this->org->manager, 'lead.view_all');
    $payload = meetingPayload($this->other, ['scheduled_date' => '2026-09-25', 'start_time' => '10:30', 'end_time' => '11:30', 'host_user_id' => $this->org->rahul->id]);

    $this->actingAs($this->org->manager)->post('/meetings', $payload)->assertSessionHasErrors(['conflicts.0', 'conflict_override']);
    $this->actingAs($this->org->manager)->post('/meetings', [...$payload, 'override_conflict' => true])->assertSessionHasNoErrors();

    $created = Meeting::latest('id')->first();
    expect($created->host_user_id)->toBe($this->org->rahul->id)
        ->and(AuditLog::where('action', AuditAction::MeetingConflictOverridden->value)->where('entity_id', $created->id)->exists())->toBeTrue();
});

test('conflicts are compared in UTC across timezones', function () {
    // 05:00–06:00 London (BST, UTC+1) = 09:30–10:30 IST → overlaps 10:00–11:00 IST.
    conflictPost($this, ['start_time' => '05:00', 'end_time' => '06:00', 'timezone' => 'Europe/London'])->assertSessionHasErrors('conflicts.0');
    // 06:30–07:30 London = 11:00–12:00 IST → touching, allowed.
    conflictPost($this, ['start_time' => '06:30', 'end_time' => '07:30', 'timezone' => 'Europe/London'])->assertSessionHasNoErrors();
});

test('conflict checking can be switched off in settings', function () {
    app(SettingService::class)->updateGroup('meeting', ['meeting.conflict_checking' => false]);

    conflictPost($this, ['start_time' => '10:30', 'end_time' => '11:30'])->assertSessionHasNoErrors();
});
