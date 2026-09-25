<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\MeetingParticipant;
use App\Models\MeetingReminder;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai', 'phone' => '9876500001', 'email' => 'amit@example.com']);
    $this->meeting = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25']);
});

test('the lead participant snapshots contact details', function () {
    $this->actingAs($this->org->rahul)->post("/meetings/{$this->meeting->id}/participants", ['type' => 'lead'])->assertSessionHasNoErrors();

    $p = $this->meeting->participants()->sole();
    $this->lead->forceFill(['phone' => '9000000000'])->save();

    expect($p->fresh()->name)->toBe('Amit Desai')
        ->and($p->fresh()->phone)->toBe('9876500001')
        ->and($p->fresh()->email)->toBe('amit@example.com')
        ->and(AuditLog::where('action', AuditAction::MeetingParticipantAdded->value)->exists())->toBeTrue();

    $this->actingAs($this->org->rahul)->post("/meetings/{$this->meeting->id}/participants", ['type' => 'lead'])->assertSessionHasErrors('type');
});

test('external participants are stored but receive nothing', function () {
    $this->actingAs($this->org->rahul)->post("/meetings/{$this->meeting->id}/participants", ['type' => 'external', 'name' => 'Consultant', 'email' => 'c@example.com'])->assertSessionHasNoErrors();

    $p = $this->meeting->participants()->sole();
    expect($p->invitation_status)->toBe(MeetingParticipant::INVITE_NOT_SENT)
        ->and(MeetingReminder::whereNull('user_id')->count())->toBe(0);
});

test('an admin can be invited and gets reminders; removing them cancels their reminders', function () {
    $this->actingAs($this->org->rahul)->post("/meetings/{$this->meeting->id}/participants", ['type' => 'user', 'user_id' => $this->org->admin->id])->assertSessionHasNoErrors();
    expect($this->meeting->reminders()->where('user_id', $this->org->admin->id)->where('status', 'pending')->count())->toBe(1);

    $p = $this->meeting->participants()->where('user_id', $this->org->admin->id)->sole();
    $this->actingAs($this->org->rahul)->delete("/meetings/{$this->meeting->id}/participants/{$p->id}")->assertSessionHasNoErrors();

    expect($this->meeting->reminders()->where('user_id', $this->org->admin->id)->where('status', 'pending')->count())->toBe(0)
        ->and(AuditLog::where('action', AuditAction::MeetingParticipantRemoved->value)->exists())->toBeTrue();
});

test('users who cannot see the lead, inactive users and the host cannot be added', function () {
    $id = $this->meeting->id;
    foreach ([$this->org->outsider, $this->org->manager, $this->org->rahul] as $user) {
        $this->actingAs($this->org->rahul)->post("/meetings/{$id}/participants", ['type' => 'user', 'user_id' => $user->id])->assertSessionHasErrors('user_id');
    }

    $this->org->admin->forceFill(['is_active' => false])->save();
    $this->actingAs($this->org->rahul)->post("/meetings/{$id}/participants", ['type' => 'user', 'user_id' => $this->org->admin->id])->assertSessionHasErrors('user_id');

    expect($this->meeting->participants()->count())->toBe(0);
});

test('the participant search only returns invitable active users who can see the lead', function () {
    $names = collect($this->actingAs($this->org->rahul)->getJson("/meetings/participants/search?lead_id={$this->lead->id}")->assertOk()->json('data'))->pluck('name');

    expect($names)->toContain('Anita Admin')
        ->not->toContain('Mehul Manager')
        ->not->toContain('Priya Patel')
        ->not->toContain('Outside Exec')
        ->not->toContain('Mumbai Manager');
});

test('a participant can confirm or decline but not respond for others', function () {
    $internal = scheduleMeeting(null, $this->org->manager, ['scheduled_date' => '2026-09-25', 'start_time' => '14:00', 'end_time' => '15:00', 'participant_user_ids' => [$this->org->rahul->id]]);

    $this->actingAs($this->org->rahul)->post("/meetings/{$internal->id}/respond", ['attendance_status' => 'confirmed'])->assertSessionHasNoErrors();
    expect($internal->participants()->sole()->attendance_status->value)->toBe('confirmed');

    $this->actingAs($this->org->rahul)->post("/meetings/{$internal->id}/respond", ['attendance_status' => 'attended'])->assertSessionHasErrors('attendance_status');
    $this->actingAs($this->org->priya)->post("/meetings/{$internal->id}/respond", ['attendance_status' => 'declined'])->assertForbidden();
});
