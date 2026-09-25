<?php

use App\Enums\AuditAction;
use App\Enums\MeetingOutcome;
use App\Enums\MeetingStatus;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\Permission;
use App\Services\ActivityService;
use App\Services\Followups\FollowupService;
use App\Services\PermissionRegistrar;
use App\Services\SettingService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
    $this->meeting = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'start_time' => '10:00', 'end_time' => '11:00', 'include_lead' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 11:05', 'Asia/Kolkata'));
});

function completeMeeting(object $test, array $data, $actor = null)
{
    return $test->actingAs($actor ?? $test->org->rahul)->post("/meetings/{$test->meeting->id}/complete", $data);
}

test('completing records outcome, notes, attendance, activity and audit', function () {
    $leadParticipant = $this->meeting->participants()->where('participant_type', 'lead')->sole();

    completeMeeting($this, ['outcome' => 'interested', 'notes' => 'Wants a quote', 'attendance' => [$leadParticipant->id => 'attended']])->assertSessionHasNoErrors();

    $m = $this->meeting->fresh();
    expect($m->status)->toBe(MeetingStatus::Completed)
        ->and($m->outcome)->toBe(MeetingOutcome::Interested)
        ->and($m->outcome_notes)->toBe('Wants a quote')
        ->and($m->completed_by)->toBe($this->org->rahul->id)
        ->and($m->completed_at)->not->toBeNull()
        ->and($leadParticipant->fresh()->attendance_status->value)->toBe('attended')
        ->and($this->lead->fresh()->last_contacted_at)->not->toBeNull();

    expect(Activity::where('subject_id', $this->lead->id)->where('type', ActivityService::MEETING_COMPLETED)->value('description'))
        ->toBe('Product Demo completed. Outcome: Interested')
        ->and(AuditLog::where('action', AuditAction::MeetingCompleted->value)->where('entity_id', $m->id)->exists())->toBeTrue();
});

test('outcome and notes are required by default and configurable', function () {
    completeMeeting($this, ['notes' => 'x'])->assertSessionHasErrors('outcome');
    completeMeeting($this, ['outcome' => 'interested'])->assertSessionHasErrors('notes');
    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Scheduled);

    app(SettingService::class)->updateGroup('meeting', ['meeting.require_outcome' => false, 'meeting.require_notes_on_complete' => false]);
    completeMeeting($this, [])->assertSessionHasNoErrors();
    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Completed);
});

test('a completed or cancelled meeting cannot be completed again', function () {
    completeMeeting($this, ['outcome' => 'interested', 'notes' => 'x'])->assertSessionHasNoErrors();
    completeMeeting($this, ['outcome' => 'won', 'notes' => 'again'])->assertForbidden();
    expect($this->meeting->fresh()->outcome)->toBe(MeetingOutcome::Interested);
});

test('§75: scheduling the next follow-up goes through FollowupService and updates next_followup_at', function () {
    $spy = Mockery::spy(app(FollowupService::class));
    $this->app->instance(FollowupService::class, $spy);

    completeMeeting($this, [
        'outcome' => 'proposal_required',
        'notes' => 'Send proposal',
        'schedule_followup' => true,
        'followup' => ['followup_type_id' => followupTypeId(), 'scheduled_date' => '2026-09-26', 'scheduled_time' => '11:00', 'priority' => 'high', 'title' => 'Send proposal'],
    ])->assertSessionHasNoErrors();

    $spy->shouldHaveReceived('create')->once();

    $followup = Followup::where('lead_id', $this->lead->id)->sole();
    expect($followup->title)->toBe('Send proposal')
        ->and($followup->assigned_to)->toBe($this->org->rahul->id)
        ->and($followup->scheduled_at->equalTo(ist('2026-09-26 11:00')))->toBeTrue()
        ->and(nextFollowup($this->lead))->toBe(ist('2026-09-26 11:00')->toDateTimeString())
        ->and(Activity::where('subject_id', $this->lead->id)->where('type', ActivityService::FOLLOWUP_CREATED)->exists())->toBeTrue()
        ->and(AuditLog::where('action', AuditAction::FollowupCreated->value)->where('entity_id', $followup->id)->exists())->toBeTrue();
});

test('an invalid follow-up rolls back the whole completion', function () {
    completeMeeting($this, [
        'outcome' => 'interested',
        'notes' => 'x',
        'schedule_followup' => true,
        'followup' => ['followup_type_id' => followupTypeId(), 'scheduled_date' => '2026-09-20', 'scheduled_time' => '11:00'],
    ])->assertSessionHasErrors('followup.scheduled_time');

    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Scheduled)
        ->and(Followup::count())->toBe(0);
});

test('§76: a lead status change on completion goes through LeadService', function () {
    completeMeeting($this, ['outcome' => 'interested', 'notes' => 'Qualified', 'status_id' => leadStatusId('interested')])->assertSessionHasNoErrors();

    expect($this->lead->fresh()->status_id)->toBe(leadStatusId('interested'))
        ->and(Activity::where('subject_id', $this->lead->id)->where('type', ActivityService::STATUS_CHANGED)->exists())->toBeTrue()
        ->and(AuditLog::where('action', AuditAction::LeadStatusChanged->value)->where('entity_id', $this->lead->id)->exists())->toBeTrue();
});

test('§76: without lead.change_status the status change is rejected and nothing is completed', function () {
    $this->org->rahul->permissionOverrides()->attach(Permission::where('name', 'lead.change_status')->value('id'), ['type' => 'deny']);
    app(PermissionRegistrar::class)->flushUser($this->org->rahul);

    completeMeeting($this, ['outcome' => 'interested', 'notes' => 'x', 'status_id' => leadStatusId('interested')])->assertSessionHasErrors('status_id');

    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Scheduled)
        ->and($this->lead->fresh()->status_id)->not->toBe(leadStatusId('interested'));
});

test('§77: Lost requires a lost reason', function () {
    completeMeeting($this, ['outcome' => 'lost', 'notes' => 'Went with competitor', 'status_id' => leadStatusId('lost')])->assertSessionHasErrors('lost_reason_id');
    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Scheduled)
        ->and($this->lead->fresh()->status_id)->not->toBe(leadStatusId('lost'));

    completeMeeting($this, ['outcome' => 'lost', 'notes' => 'Went with competitor', 'status_id' => leadStatusId('lost'), 'lost_reason_id' => LostReason::value('id')])->assertSessionHasNoErrors();
    expect($this->lead->fresh()->status_id)->toBe(leadStatusId('lost'))
        ->and($this->meeting->fresh()->status)->toBe(MeetingStatus::Completed);
});

test('the outcome alone never changes the lead status', function () {
    $before = $this->lead->fresh()->status_id;
    completeMeeting($this, ['outcome' => 'won', 'notes' => 'Signed'])->assertSessionHasNoErrors();
    expect($this->lead->fresh()->status_id)->toBe($before);
});

test('no-show marks absent participants, never changes lead status and is audited', function () {
    $before = $this->lead->fresh()->status_id;
    $leadParticipant = $this->meeting->participants()->where('participant_type', 'lead')->sole();

    $this->actingAs($this->org->rahul)->post("/meetings/{$this->meeting->id}/no-show", ['absent_participant_ids' => [$leadParticipant->id], 'notes' => 'Did not join'])->assertSessionHasNoErrors();

    $m = $this->meeting->fresh();
    expect($m->status)->toBe(MeetingStatus::NoShow)
        ->and($m->outcome)->toBe(MeetingOutcome::NoShow)
        ->and($leadParticipant->fresh()->attendance_status->value)->toBe('absent')
        ->and($this->lead->fresh()->status_id)->toBe($before)
        ->and($this->lead->fresh()->last_contacted_at)->toBeNull()
        ->and(Activity::where('subject_id', $this->lead->id)->where('type', ActivityService::MEETING_NO_SHOW)->exists())->toBeTrue()
        ->and(AuditLog::where('action', AuditAction::MeetingNoShow->value)->exists())->toBeTrue();
});

test('the meeting controller never creates follow-up rows directly', function () {
    $source = file_get_contents(app_path('Http/Controllers/Meetings/MeetingController.php'))
        .file_get_contents(app_path('Http/Controllers/Meetings/MeetingCompletionController.php'));

    expect($source)->not->toContain('Followup::create')
        ->and($source)->not->toContain('followups()->create')
        ->and($source)->not->toContain("DB::table('followups')");
});
