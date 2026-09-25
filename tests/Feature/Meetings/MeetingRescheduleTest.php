<?php

use App\Enums\AuditAction;
use App\Enums\MeetingStatus;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Meeting;
use App\Services\ActivityService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->meeting = scheduleMeeting(null, $this->org->manager, [
        'scheduled_date' => '2026-09-25', 'start_time' => '10:00', 'end_time' => '11:00',
        'participant_user_ids' => [$this->org->rahul->id],
        'external_participants' => [['name' => 'Vendor Rep', 'email' => 'rep@example.com']],
    ]);
});

test('rescheduling keeps the original as history and creates a linked meeting with the same participants', function () {
    $this->actingAs($this->org->manager)
        ->post("/meetings/{$this->meeting->id}/reschedule", ['scheduled_date' => '2026-09-26', 'start_time' => '15:00', 'end_time' => '16:30', 'reason' => 'Client asked'])
        ->assertSessionHasNoErrors();

    $old = $this->meeting->fresh();
    $new = Meeting::where('rescheduled_from_id', $old->id)->sole();

    expect($old->status)->toBe(MeetingStatus::Rescheduled)
        ->and($old->reschedule_reason)->toBe('Client asked')
        ->and($old->start_at->equalTo(ist('2026-09-25 10:00')))->toBeTrue()
        ->and($new->status)->toBe(MeetingStatus::Scheduled)
        ->and($new->meeting_number)->not->toBe($old->meeting_number)
        ->and($new->start_at->equalTo(ist('2026-09-26 15:00')))->toBeTrue()
        ->and($new->end_at->equalTo(ist('2026-09-26 16:30')))->toBeTrue()
        ->and($new->participants()->pluck('name')->sort()->values()->all())->toBe(['Rahul Sharma', 'Vendor Rep'])
        ->and($old->participants()->count())->toBe(2)
        ->and(AuditLog::where('action', AuditAction::MeetingRescheduled->value)->where('entity_id', $old->id)->exists())->toBeTrue();
});

test('the same time, closed meetings and past times cannot be rescheduled to', function () {
    $id = $this->meeting->id;
    $this->actingAs($this->org->manager)->post("/meetings/{$id}/reschedule", ['scheduled_date' => '2026-09-25', 'start_time' => '10:00', 'end_time' => '11:00'])->assertSessionHasErrors('start_time');
    $this->actingAs($this->org->manager)->post("/meetings/{$id}/reschedule", ['scheduled_date' => '2026-09-20', 'start_time' => '10:00', 'end_time' => '11:00'])->assertSessionHasErrors('start_time');

    $this->actingAs($this->org->manager)->post("/meetings/{$id}/cancel", ['reason' => 'Off'])->assertSessionHasNoErrors();
    $this->actingAs($this->org->manager)->post("/meetings/{$id}/reschedule", ['scheduled_date' => '2026-09-27', 'start_time' => '10:00', 'end_time' => '11:00'])->assertForbidden();
});

test('a participant who is not the host cannot reschedule, cancel or edit', function () {
    $id = $this->meeting->id;
    $this->actingAs($this->org->rahul)->get("/meetings/{$id}")->assertOk();
    $this->actingAs($this->org->rahul)->post("/meetings/{$id}/reschedule", ['scheduled_date' => '2026-09-27', 'start_time' => '10:00', 'end_time' => '11:00'])->assertForbidden();
    $this->actingAs($this->org->rahul)->post("/meetings/{$id}/cancel", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($this->org->rahul)->put("/meetings/{$id}", ['title' => 'x'])->assertForbidden();
});

test('rescheduling a lead meeting writes a lead activity', function () {
    $m = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25', 'start_time' => '12:00', 'end_time' => '13:00']);
    $this->actingAs($this->org->rahul)->post("/meetings/{$m->id}/reschedule", ['scheduled_date' => '2026-09-26', 'start_time' => '12:00', 'end_time' => '13:00'])->assertSessionHasNoErrors();

    expect(Activity::where('subject_id', $this->lead->id)->where('type', ActivityService::MEETING_RESCHEDULED)->value('description'))
        ->toContain('Rescheduled the Product Demo from');
});

test('editing never changes the schedule; only reschedule does', function () {
    $this->actingAs($this->org->manager)->put("/meetings/{$this->meeting->id}", ['title' => 'Renamed', 'scheduled_date' => '2026-10-01', 'start_time' => '09:00', 'end_time' => '09:30'])->assertSessionHasNoErrors();

    $m = $this->meeting->fresh();
    expect($m->title)->toBe('Renamed')
        ->and($m->start_at->equalTo(ist('2026-09-25 10:00')))->toBeTrue();
});
