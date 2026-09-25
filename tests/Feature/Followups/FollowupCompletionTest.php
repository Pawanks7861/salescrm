<?php

use App\Enums\FollowupStatus;
use App\Models\Activity;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Permission;
use App\Services\PermissionRegistrar;
use App\Services\SettingService;
use App\Support\Permissions;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->org = salesOrg();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Kolkata'));
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create(['last_contacted_at' => null]);
    $this->followup = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '12:00']);
    $this->statusBefore = $this->lead->fresh()->status_id;
});

test('completing records outcome, notes, next action, completer and timeline entry', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/complete", [
        'outcome' => 'interested',
        'notes' => 'Wants a quote',
        'next_action' => 'Send proposal',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $f = $this->followup->fresh();
    expect($f->status)->toBe(FollowupStatus::Completed)
        ->and($f->outcome->value)->toBe('interested')
        ->and($f->notes)->toBe('Wants a quote')
        ->and($f->next_action)->toBe('Send proposal')
        ->and($f->completed_by)->toBe($this->org->rahul->id)
        ->and($f->completed_at->equalTo(now()))->toBeTrue();

    expect(Activity::where('subject_id', $this->lead->id)->where('type', 'followup_completed')->sole()->description)
        ->toBe('Completed the Call follow-up. Outcome: Interested');
});

test('outcome is required by default and optional when the setting is off', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/complete", [])
        ->assertSessionHasErrors('outcome');
    expect($this->followup->fresh()->isPending())->toBeTrue();

    app(SettingService::class)->updateGroup('followup', ['followup.require_outcome' => false]);
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/complete", [])
        ->assertSessionHasNoErrors();
    expect($this->followup->fresh()->status)->toBe(FollowupStatus::Completed);
});

test('completion does not change the lead status automatically', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/complete", ['outcome' => 'not_interested']);

    expect($this->lead->fresh()->status_id)->toBe($this->statusBefore);
});

test('last_contacted_at updates only for outcomes that prove contact', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/complete", ['outcome' => 'no_answer']);
    expect($this->lead->fresh()->last_contacted_at)->toBeNull();

    $second = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '14:00']);
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$second->id}/complete", ['outcome' => 'connected']);
    expect($this->lead->fresh()->last_contacted_at->equalTo(now()))->toBeTrue();
});

test('last_contacted_at never moves backwards', function () {
    $later = now()->addDay();
    $this->lead->forceFill(['last_contacted_at' => $later])->save();

    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/complete", ['outcome' => 'connected']);
    expect($this->lead->fresh()->last_contacted_at->equalTo($later))->toBeTrue();
});

test('the lead status can optionally change through LeadService with lead.change_status', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/complete", [
        'outcome' => 'connected',
        'status_id' => leadStatusId('contacted'),
    ])->assertSessionHasNoErrors();

    expect($this->lead->fresh()->status_id)->toBe(leadStatusId('contacted'))
        ->and(Activity::where('subject_id', $this->lead->id)->where('type', 'status_changed')->exists())->toBeTrue();
});

test('without lead.change_status the status change is rejected and nothing is saved', function () {
    $this->org->rahul->permissionOverrides()->attach(Permission::where('name', Permissions::LEAD_CHANGE_STATUS)->value('id'), ['type' => 'deny']);
    app(PermissionRegistrar::class)->flushUser($this->org->rahul);

    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/complete", [
        'outcome' => 'connected',
        'status_id' => leadStatusId('contacted'),
    ])->assertSessionHasErrors('status_id');

    expect($this->followup->fresh()->isPending())->toBeTrue()
        ->and($this->lead->fresh()->status_id)->toBe($this->statusBefore);
});

test('an invalid lost status change rolls back the whole completion', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/complete", [
        'outcome' => 'not_interested',
        'status_id' => leadStatusId('lost'),
    ])->assertSessionHasErrors();

    expect($this->followup->fresh()->isPending())->toBeTrue()
        ->and($this->lead->fresh()->status_id)->toBe($this->statusBefore);
});

test('complete and schedule next creates a linked pending follow-up atomically', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/complete", [
        'outcome' => 'call_back_later',
        'schedule_next' => true,
        'next' => ['followup_type_id' => followupTypeId('whatsapp'), 'scheduled_date' => '2026-09-25', 'scheduled_time' => '10:00'],
    ])->assertSessionHasNoErrors();

    $next = Followup::where('status', 'pending')->sole();
    expect($next->followup_type_id)->toBe(followupTypeId('whatsapp'))
        ->and($next->assigned_to)->toBe($this->org->rahul->id)
        ->and($next->scheduled_at->utc()->toDateTimeString())->toBe('2026-09-25 04:30:00');
});

test('an invalid next follow-up rolls back the completion', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/complete", [
        'outcome' => 'connected',
        'schedule_next' => true,
        'next' => ['followup_type_id' => followupTypeId(), 'scheduled_date' => '2026-09-20', 'scheduled_time' => '10:00'],
    ])->assertSessionHasErrors('next.scheduled_time');

    expect($this->followup->fresh()->isPending())->toBeTrue()
        ->and(Followup::count())->toBe(1);

    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/complete", [
        'outcome' => 'connected',
        'schedule_next' => true,
        'next' => [],
    ])->assertSessionHasErrors(['next.followup_type_id', 'next.scheduled_date', 'next.scheduled_time']);
});

test('an overdue follow-up can still be completed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Asia/Kolkata'));
    expect($this->followup->fresh()->isOverdue())->toBeTrue();

    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->followup->id}/complete", ['outcome' => 'connected'])->assertSessionHasNoErrors();
    expect($this->followup->fresh()->status)->toBe(FollowupStatus::Completed);
});

test('an admin can complete someone else\'s follow-up; the completer is the admin', function () {
    $this->actingAs($this->org->admin)->post("/follow-ups/{$this->followup->id}/complete", ['outcome' => 'connected'])->assertSessionHasNoErrors();
    expect($this->followup->fresh()->completed_by)->toBe($this->org->admin->id);
});

test('a legacy team manager cannot complete a member follow-up', function () {
    $this->actingAs($this->org->manager)->post("/follow-ups/{$this->followup->id}/complete", ['outcome' => 'connected'])->assertForbidden();
    expect($this->followup->fresh()->completed_by)->toBeNull();
});
