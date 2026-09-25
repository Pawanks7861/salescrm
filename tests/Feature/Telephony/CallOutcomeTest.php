<?php

use App\Enums\AuditAction;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\CallDisposition;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Meeting;
use App\Services\SettingService;

function dispositionId(string $slug): int
{
    return (int) CallDisposition::where('slug', $slug)->value('id');
}

beforeEach(function () {
    $this->org = salesOrg();
    telephonySetup([$this->org->rahul, $this->org->priya]);
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->call = finishCall($this, startCall($this->lead, $this->org->rahul), 'completed', 402);
});

test('the default dispositions are seeded', function () {
    expect(CallDisposition::ordered()->pluck('name')->all())->toBe([
        'Connected', 'Interested', 'Call Back', 'No Answer', 'Busy', 'Not Interested', 'Wrong Number',
        'Proposal Required', 'Meeting Required', 'Follow-up Required', 'Converted', 'Other',
    ]);
});

test('connected calls require a disposition; busy calls do not by default', function () {
    expect($this->call->requires_disposition)->toBeTrue();

    $busy = finishCall($this, startCall($this->lead, $this->org->rahul), 'busy');
    expect($busy->requires_disposition)->toBeFalse();
});

test('busy calls can be configured to require a disposition', function () {
    app(SettingService::class)->updateGroup('telephony', ['telephony.require_disposition_unconnected' => true]);

    expect(finishCall($this, startCall($this->lead, $this->org->rahul), 'busy')->requires_disposition)->toBeTrue();
});

test('saving an outcome records the disposition, notes, activity and audit', function () {
    $this->actingAs($this->org->rahul)
        ->post(route('calls.outcome', $this->call), ['disposition_id' => dispositionId('interested'), 'notes' => 'Wants pricing', 'next_action' => 'none'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $call = $this->call->fresh();
    expect($call->disposition_id)->toBe(dispositionId('interested'))
        ->and($call->notes)->toBe('Wants pricing')
        ->and($call->disposition_by)->toBe($this->org->rahul->id)
        ->and(Activity::where('type', 'call_outcome')->value('description'))->toBe('Call outcome: Interested.')
        ->and(AuditLog::where('action', AuditAction::CallDispositionAdded->value)->exists())->toBeTrue()
        ->and(AuditLog::where('action', AuditAction::CallNotesUpdated->value)->exists())->toBeTrue();
});

test('a disposition is required', function () {
    $this->actingAs($this->org->rahul)->post(route('calls.outcome', $this->call), ['notes' => 'x'])->assertSessionHasErrors('disposition_id');
});

test('a call still in progress cannot be dispositioned', function () {
    $open = startCall($this->lead, $this->org->rahul);

    $this->actingAs($this->org->rahul)->post(route('calls.outcome', $open), ['disposition_id' => dispositionId('connected')])->assertSessionHasErrors('disposition_id');
});

test('dispositions that need a next action enforce it', function () {
    $this->actingAs($this->org->rahul)
        ->post(route('calls.outcome', $this->call), ['disposition_id' => dispositionId('call_back'), 'next_action' => 'none'])
        ->assertSessionHasErrors('next_action');
});

test('the outcome can schedule a follow-up through FollowupService', function () {
    $slot = crmSlot(now()->addDay()->setTime(11, 0));

    $this->actingAs($this->org->rahul)->post(route('calls.outcome', $this->call), [
        'disposition_id' => dispositionId('call_back'),
        'next_action' => 'followup',
        'followup' => ['followup_type_id' => followupTypeId(), 'scheduled_date' => $slot['scheduled_date'], 'scheduled_time' => '11:00', 'priority' => 'high', 'title' => 'Call back'],
    ])->assertSessionHasNoErrors();

    $followup = Followup::sole();
    expect($followup->lead_id)->toBe($this->lead->id)
        ->and($this->call->fresh()->followup_id)->toBe($followup->id)
        ->and($this->call->fresh()->next_action)->toBe('followup')
        ->and($this->lead->fresh()->next_followup_at)->not->toBeNull();
});

test('follow-up validation errors are reported under the followup key', function () {
    $this->actingAs($this->org->rahul)->post(route('calls.outcome', $this->call), [
        'disposition_id' => dispositionId('call_back'),
        'next_action' => 'followup',
        'followup' => ['followup_type_id' => followupTypeId(), 'scheduled_date' => '2020-01-01', 'scheduled_time' => '11:00'],
    ])->assertSessionHasErrors('followup.scheduled_time');

    expect($this->call->fresh()->disposition_id)->toBeNull()->and(Followup::count())->toBe(0);
});

test('the outcome can schedule a meeting through MeetingService', function () {
    $payload = meetingPayload($this->lead);

    $this->actingAs($this->org->rahul)->post(route('calls.outcome', $this->call), [
        'disposition_id' => dispositionId('meeting_required'),
        'next_action' => 'meeting',
        'meeting' => collect($payload)->only(['meeting_type_id', 'title', 'scheduled_date', 'start_time', 'end_time', 'location_type', 'reminders'])->all(),
    ])->assertSessionHasNoErrors();

    expect(Meeting::sole()->lead_id)->toBe($this->lead->id)->and($this->call->fresh()->meeting_id)->toBe(Meeting::sole()->id);
});

test('changing the lead status to Lost still requires a lost reason', function () {
    $this->actingAs($this->org->rahul)->post(route('calls.outcome', $this->call), [
        'disposition_id' => dispositionId('not_interested'),
        'status_id' => leadStatusId('lost'),
    ])->assertSessionHasErrors();

    expect($this->call->fresh()->disposition_id)->toBeNull()->and($this->lead->fresh()->status_id)->not->toBe(leadStatusId('lost'));
});

test('users cannot disposition calls they cannot see', function () {
    $this->actingAs($this->org->priya)->post(route('calls.outcome', $this->call), ['disposition_id' => dispositionId('connected')])->assertForbidden();
    $this->actingAs($this->org->outsider)->post(route('calls.outcome', $this->call), ['disposition_id' => dispositionId('connected')])->assertForbidden();
});

test('changing a disposition is audited with the old value', function () {
    $this->actingAs($this->org->rahul)->post(route('calls.outcome', $this->call), ['disposition_id' => dispositionId('connected')]);
    $this->actingAs($this->org->rahul)->post(route('calls.outcome', $this->call), ['disposition_id' => dispositionId('interested')]);

    $audit = AuditLog::where('action', AuditAction::CallDispositionChanged->value)->sole();
    expect($audit->old_values_json)->toBe(['disposition' => 'Connected'])->and($audit->new_values_json['disposition'])->toBe('Interested');
});

test('call notes edits are audited with old and new values', function () {
    $this->actingAs($this->org->rahul)->patch(route('calls.notes', $this->call), ['notes' => 'First'])->assertSessionHasNoErrors();
    $this->actingAs($this->org->rahul)->patch(route('calls.notes', $this->call), ['notes' => 'Second'])->assertSessionHasNoErrors();

    $last = AuditLog::where('action', AuditAction::CallNotesUpdated->value)->latest('id')->first();
    expect($last->old_values_json)->toBe(['notes' => 'First'])->and($last->new_values_json)->toBe(['notes' => 'Second']);
});

test('provider facts cannot be edited by sales users', function () {
    $before = $this->call->fresh()->only(['provider_call_id', 'started_at', 'answered_at', 'ended_at', 'talk_duration_seconds', 'direction', 'agent_user_id', 'status', 'provider_status']);

    $this->actingAs($this->org->rahul)->post(route('calls.outcome', $this->call), [
        'disposition_id' => dispositionId('connected'),
        'talk_duration_seconds' => 9999, 'status' => 'failed', 'agent_user_id' => $this->org->priya->id,
        'provider_call_id' => 'HACK', 'direction' => 'inbound', 'started_at' => '2020-01-01 00:00:00',
    ])->assertSessionHasNoErrors();

    expect($this->call->fresh()->only(array_keys($before)))->toEqual($before);
    $this->actingAs($this->org->rahul)->put('/calls/'.$this->call->id, ['status' => 'failed'])->assertStatus(405);
    $this->actingAs($this->org->super)->delete('/calls/'.$this->call->id)->assertStatus(405);
});

test('the notes edit window is enforced when configured', function () {
    app(SettingService::class)->updateGroup('telephony', ['telephony.notes_edit_window_hours' => 1]);
    $this->call->forceFill(['ended_at' => now()->subHours(3), 'notes' => 'Original'])->save();

    $this->actingAs($this->org->rahul)->patch(route('calls.notes', $this->call), ['notes' => 'late'])->assertSessionHasErrors('notes');
    expect($this->call->fresh()->notes)->toBe('Original');

    $this->actingAs($this->org->admin)->patch(route('calls.notes', $this->call), ['notes' => 'Corrected'])->assertSessionHasNoErrors();
    expect($this->call->fresh()->notes)->toBe('Corrected');
});
