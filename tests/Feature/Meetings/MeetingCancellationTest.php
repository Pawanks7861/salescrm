<?php

use App\Enums\AuditAction;
use App\Enums\MeetingStatus;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Meeting;
use App\Services\ActivityService;
use App\Services\SettingService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->meeting = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25']);
});

test('cancelling requires a reason by default and records who and when', function () {
    $this->actingAs($this->org->rahul)->post("/meetings/{$this->meeting->id}/cancel", [])->assertSessionHasErrors('reason');
    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Scheduled);

    $this->actingAs($this->org->rahul)->post("/meetings/{$this->meeting->id}/cancel", ['reason' => 'Client travelling'])->assertSessionHasNoErrors();

    $m = $this->meeting->fresh();
    expect($m->status)->toBe(MeetingStatus::Cancelled)
        ->and($m->cancellation_reason)->toBe('Client travelling')
        ->and($m->cancelled_by)->toBe($this->org->rahul->id)
        ->and($m->cancelled_at)->not->toBeNull()
        ->and(Activity::where('subject_id', $this->lead->id)->where('type', ActivityService::MEETING_CANCELLED)->exists())->toBeTrue()
        ->and(AuditLog::where('action', AuditAction::MeetingCancelled->value)->where('entity_id', $m->id)->exists())->toBeTrue();
});

test('the cancellation reason requirement can be switched off', function () {
    app(SettingService::class)->updateGroup('meeting', ['meeting.require_cancellation_reason' => false]);
    $this->actingAs($this->org->rahul)->post("/meetings/{$this->meeting->id}/cancel")->assertSessionHasNoErrors();
    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Cancelled);
});

test('a cancelled meeting cannot be cancelled, completed or edited again', function () {
    $id = $this->meeting->id;
    $this->actingAs($this->org->rahul)->post("/meetings/{$id}/cancel", ['reason' => 'x'])->assertSessionHasNoErrors();

    $this->actingAs($this->org->rahul)->post("/meetings/{$id}/cancel", ['reason' => 'again'])->assertForbidden();
    $this->actingAs($this->org->rahul)->post("/meetings/{$id}/complete", ['outcome' => 'interested', 'notes' => 'x'])->assertForbidden();
    $this->actingAs($this->org->rahul)->put("/meetings/{$id}", ['title' => 'x'])->assertForbidden();
});

test('only users with meeting.delete can soft delete and restore; executives cancel instead', function () {
    $this->actingAs($this->org->rahul)->delete("/meetings/{$this->meeting->id}")->assertForbidden();

    $this->actingAs($this->org->admin)->delete("/meetings/{$this->meeting->id}")->assertRedirect();
    expect(Meeting::find($this->meeting->id))->toBeNull()
        ->and(Meeting::withTrashed()->find($this->meeting->id))->not->toBeNull();

    $this->actingAs($this->org->admin)->post("/meetings/{$this->meeting->id}/restore")->assertRedirect();
    expect(Meeting::find($this->meeting->id))->not->toBeNull()
        ->and(AuditLog::where('action', AuditAction::MeetingDeleted->value)->exists())->toBeTrue()
        ->and(AuditLog::where('action', AuditAction::MeetingRestored->value)->exists())->toBeTrue();
});
