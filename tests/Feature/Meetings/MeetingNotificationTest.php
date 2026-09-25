<?php

use App\Models\Lead;
use App\Notifications\Meetings\MeetingActivityNotification;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
});

function meetingEvents($user): array
{
    return $user->notifications()->where('type', MeetingActivityNotification::class)->get()->map(fn ($n) => $n->data['event'])->all();
}

test('an admin making rahul the host notifies rahul, never the actor', function () {
    $this->actingAs($this->org->admin)
        ->post('/meetings', meetingPayload($this->lead, ['scheduled_date' => '2026-09-25', 'host_user_id' => $this->org->rahul->id]))
        ->assertSessionHasNoErrors();

    $note = $this->org->rahul->notifications()->sole();
    expect($note->data['event'])->toBe('meeting_assigned')
        ->and($note->data['category'])->toBe('meeting')
        ->and($note->data['message'])->toStartWith('Anita Admin made you the host of Product Demo with Amit Desai on ')
        ->and(meetingEvents($this->org->admin))->toBe([]);
});

test('scheduling your own meeting sends no notification to yourself', function () {
    scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25']);
    expect($this->org->rahul->notifications()->count())->toBe(0);
});

test('invited participants are notified; reschedule, cancel and complete notify attendees except the actor', function () {
    $m = scheduleMeeting(null, $this->org->manager, ['title' => 'Review', 'scheduled_date' => '2026-09-24', 'start_time' => '10:00', 'end_time' => '10:30', 'participant_user_ids' => [$this->org->rahul->id]]);
    expect(meetingEvents($this->org->rahul))->toBe(['meeting_scheduled']);

    $this->actingAs($this->org->manager)->post("/meetings/{$m->id}/reschedule", ['scheduled_date' => '2026-09-24', 'start_time' => '11:00', 'end_time' => '11:30'])->assertSessionHasNoErrors();
    $new = $m->fresh()->rescheduledTo;
    expect(meetingEvents($this->org->rahul))->toContain('meeting_rescheduled');

    $this->travelTo(CarbonImmutable::parse('2026-09-24 11:40', 'Asia/Kolkata'));
    $this->actingAs($this->org->manager)->post("/meetings/{$new->id}/complete", ['outcome' => 'other', 'notes' => 'Done'])->assertSessionHasNoErrors();
    expect(meetingEvents($this->org->rahul))->toContain('meeting_completed')
        ->and(meetingEvents($this->org->manager))->toBe([]);
});

test('inactive users and users who cannot see the meeting are not notified', function () {
    $this->org->rahul->forceFill(['is_active' => false])->save();
    $this->actingAs($this->org->admin)
        ->post('/meetings', meetingPayload($this->lead, ['scheduled_date' => '2026-09-25', 'host_user_id' => $this->org->rahul->id]));

    expect($this->org->rahul->notifications()->count())->toBe(0);
});

test('notification links are re-authorized when opened', function () {
    $this->actingAs($this->org->admin)
        ->post('/meetings', meetingPayload($this->lead, ['scheduled_date' => '2026-09-25', 'host_user_id' => $this->org->rahul->id]))
        ->assertSessionHasNoErrors();

    $row = $this->actingAs($this->org->rahul)->getJson('/notifications/recent')->json('data.0');
    expect($row['stale'])->toBeFalse()->and($row['target'])->toStartWith('/meetings/');

    $this->lead->forceFill(['assigned_to' => $this->org->priya->id])->save();
    $row = $this->actingAs($this->org->rahul)->getJson('/notifications/recent')->json('data.0');
    expect($row['stale'])->toBeTrue()->and($row['message'])->not->toContain('Amit Desai');
});
