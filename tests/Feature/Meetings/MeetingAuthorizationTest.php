<?php

use App\Enums\AuditAction;
use App\Enums\MeetingStatus;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Permission;
use App\Services\PermissionRegistrar;
use App\Support\Permissions;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->meeting = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25']);
});

test('the phase 4 permissions exist once and are granted per role', function () {
    foreach ([Permissions::MEETING_COMPLETE, Permissions::MEETING_ASSIGN, Permissions::MEETING_CREATE_WITHOUT_LEAD, Permissions::MEETING_SCHEDULE_PAST, Permissions::MEETING_CONFIGURE] as $name) {
        expect(Permission::where('name', $name)->count())->toBe(1);
    }

    $exec = $this->org->rahul;
    expect($exec->hasPermission(Permissions::MEETING_COMPLETE))->toBeTrue()
        ->and($exec->hasPermission(Permissions::MEETING_ASSIGN))->toBeFalse()
        ->and($exec->hasPermission(Permissions::MEETING_CREATE_WITHOUT_LEAD))->toBeFalse()
        ->and($exec->hasPermission(Permissions::MEETING_OVERRIDE_CONFLICT))->toBeFalse()
        ->and($exec->hasPermission(Permissions::MEETING_CONFIGURE))->toBeFalse()
        ->and($this->org->manager->hasPermission(Permissions::MEETING_ASSIGN))->toBeTrue()
        ->and($this->org->manager->hasPermission(Permissions::MEETING_OVERRIDE_CONFLICT))->toBeTrue()
        ->and($this->org->admin->hasPermission(Permissions::MEETING_CONFIGURE))->toBeTrue();
});

test('the host can confirm and start; start requires meeting.complete', function () {
    $this->actingAs($this->org->rahul)->post("/meetings/{$this->meeting->id}/confirm")->assertSessionHasNoErrors();
    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Confirmed);

    $this->org->rahul->permissionOverrides()->attach(Permission::where('name', Permissions::MEETING_COMPLETE)->value('id'), ['type' => 'deny']);
    app(PermissionRegistrar::class)->flushUser($this->org->rahul);

    $this->actingAs($this->org->rahul)->post("/meetings/{$this->meeting->id}/start")->assertForbidden();
    $this->actingAs($this->org->rahul)->post("/meetings/{$this->meeting->id}/complete", ['outcome' => 'interested', 'notes' => 'x'])->assertForbidden();
});

test('meeting pages require a meeting view permission', function () {
    foreach ([Permissions::MEETING_VIEW, Permissions::MEETING_VIEW_ALL] as $name) {
        $this->org->rahul->permissionOverrides()->syncWithoutDetaching([Permission::where('name', $name)->value('id') => ['type' => 'deny']]);
    }
    app(PermissionRegistrar::class)->flushUser($this->org->rahul);

    $this->actingAs($this->org->rahul)->get('/meetings')->assertForbidden();
    $this->actingAs($this->org->rahul)->get('/calendar')->assertForbidden();
    $this->actingAs($this->org->rahul)->getJson('/calendar/events?start=2026-09-21T00:00:00&end=2026-09-28T00:00:00')->assertForbidden();
    expect($this->actingAs($this->org->rahul)->get('/dashboard')->inertiaProps('meetings'))->toBeNull();
});

test('forbidden meeting access is audited', function () {
    $priyaLead = Lead::factory()->assignedTo($this->org->priya)->create();
    $priyaMeeting = scheduleMeeting($priyaLead, $this->org->priya, ['scheduled_date' => '2026-09-25', 'start_time' => '15:00', 'end_time' => '16:00']);

    $this->actingAs($this->org->rahul)->get("/meetings/{$priyaMeeting->id}")->assertForbidden();

    expect(AuditLog::where('action', AuditAction::MeetingAccessDenied->value)->where('user_id', $this->org->rahul->id)->exists())->toBeTrue();
});

test('the scheduler can view and edit a meeting hosted by someone else', function () {
    $this->org->admin->permissionOverrides()->attach(
        Permission::where('name', Permissions::MEETING_VIEW_ALL)->value('id'),
        ['type' => 'deny'],
    );
    app(PermissionRegistrar::class)->flushUser($this->org->admin);
    $this->org->admin->forgetResolvedPermissions();

    $this->actingAs($this->org->admin)
        ->post('/meetings', meetingPayload($this->lead, [
            'scheduled_date' => '2026-09-25',
            'host_user_id' => $this->org->rahul->id,
            'title' => 'Scheduled for the owner',
        ]))
        ->assertSessionHasNoErrors();

    $meeting = Meeting::query()->where('title', 'Scheduled for the owner')->first();
    expect($meeting)->not->toBeNull()
        ->and($meeting->host_user_id)->toBe($this->org->rahul->id)
        ->and($meeting->created_by)->toBe($this->org->admin->id);

    $this->actingAs($this->org->admin)->get("/meetings/{$meeting->id}")->assertOk();
    $this->actingAs($this->org->admin)
        ->put("/meetings/{$meeting->id}", ['title' => 'Updated by the scheduler'])
        ->assertSessionHasNoErrors();

    expect($meeting->fresh()->title)->toBe('Updated by the scheduler');
    expect(collect($this->actingAs($this->org->admin)->get('/meetings?tab=all')->inertiaProps('meetings.data'))->pluck('id'))
        ->toContain($meeting->id);
});
