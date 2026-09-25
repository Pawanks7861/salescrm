<?php

use App\Enums\MeetingStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\MeetingType;
use App\Models\Permission;
use App\Services\ActivityService;
use App\Services\PermissionRegistrar;
use App\Services\SettingService;
use App\Support\Permissions;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
});

test('an executive schedules a meeting on their lead; server-owned fields are set by the server', function () {
    $this->actingAs($this->org->rahul)
        ->post('/meetings', meetingPayload($this->lead, [
            'scheduled_date' => '2026-09-25',
            'meeting_number' => 'HACK-1',
            'status' => 'completed',
            'team_id' => $this->org->otherTeam->id,
            'created_by' => $this->org->admin->id,
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $m = Meeting::sole();
    expect($m->meeting_number)->toStartWith('MTG-2026-')
        ->and($m->status)->toBe(MeetingStatus::Scheduled)
        ->and($m->host_user_id)->toBe($this->org->rahul->id)
        ->and($m->team_id)->toBeNull()
        ->and($m->created_by)->toBe($this->org->rahul->id)
        ->and($m->start_at->equalTo(ist('2026-09-25 10:00')))->toBeTrue()
        ->and($m->end_at->equalTo(ist('2026-09-25 11:00')))->toBeTrue()
        ->and($m->timezone)->toBe('Asia/Kolkata')
        ->and(Activity::where('subject_id', $this->lead->id)->where('type', ActivityService::MEETING_CREATED)->exists())->toBeTrue();
});

test('meeting numbers are sequential per year', function () {
    $a = scheduleMeeting($this->lead, $this->org->rahul, ['start_time' => '10:00', 'end_time' => '10:30']);
    $b = scheduleMeeting($this->lead, $this->org->rahul, ['start_time' => '11:00', 'end_time' => '11:30']);

    expect($a->meeting_number)->toBe('MTG-2026-000001')
        ->and($b->meeting_number)->toBe('MTG-2026-000002');
});

test('end must be after start, max 24 hours, and dates must be valid', function () {
    $post = fn (array $o) => $this->actingAs($this->org->rahul)->post('/meetings', meetingPayload($this->lead, $o));

    $post(['start_time' => '11:00', 'end_time' => '10:00'])->assertSessionHasErrors('end_time');
    $post(['start_time' => '11:00', 'end_time' => '11:00'])->assertSessionHasErrors('end_time');
    $post(['scheduled_date' => '2026-09-25', 'start_time' => '10:00', 'end_date' => '2026-09-26', 'end_time' => '10:30'])->assertSessionHasErrors('end_time');
    $post(['scheduled_date' => '2026-02-30'])->assertSessionHasErrors('scheduled_date');
    $post(['start_time' => '25:00'])->assertSessionHasErrors('start_time');
    $post(['meeting_type_id' => 999999])->assertSessionHasErrors('meeting_type_id');

    expect(Meeting::count())->toBe(0);
});

test('past meetings are rejected unless allowed by permission or setting', function () {
    $past = ['scheduled_date' => '2026-09-23', 'start_time' => '10:00', 'end_time' => '11:00'];

    $this->actingAs($this->org->rahul)->post('/meetings', meetingPayload($this->lead, $past))->assertSessionHasErrors('start_time');

    app(SettingService::class)->updateGroup('meeting', ['meeting.allow_past' => true]);
    $this->actingAs($this->org->rahul)->post('/meetings', meetingPayload($this->lead, $past))->assertSessionHasNoErrors();
});

test('a meeting URL must be http(s); no fake meeting links are generated', function () {
    $this->actingAs($this->org->rahul)->post('/meetings', meetingPayload($this->lead, ['meeting_url' => 'javascript:alert(1)']))->assertSessionHasErrors('meeting_url');

    $this->actingAs($this->org->rahul)->post('/meetings', meetingPayload($this->lead, ['meeting_type_id' => meetingTypeId('zoom'), 'location_type' => 'online']))->assertSessionHasNoErrors();
    expect(Meeting::sole()->meeting_url)->toBeNull();
});

test('meetings without a lead require meeting.create_without_lead', function () {
    $this->actingAs($this->org->rahul)->post('/meetings', meetingPayload(null))->assertForbidden();
    $this->actingAs($this->org->manager)->post('/meetings', meetingPayload(null, ['title' => 'Team review']))->assertSessionHasNoErrors();

    expect(Meeting::sole()->lead_id)->toBeNull();
});

test('archived leads cannot receive new meetings', function () {
    $this->lead->delete();
    $this->actingAs($this->org->rahul)->post('/meetings', meetingPayload($this->lead))->assertNotFound();
    expect(Meeting::count())->toBe(0);
});

test('users without meeting.create cannot schedule', function () {
    $this->org->rahul->permissionOverrides()->attach(Permission::where('name', Permissions::MEETING_CREATE)->value('id'), ['type' => 'deny']);
    app(PermissionRegistrar::class)->flushUser($this->org->rahul);

    $this->actingAs($this->org->rahul)->post('/meetings', meetingPayload($this->lead))->assertForbidden();
});

test('the default reminder comes from settings when none are chosen', function () {
    $payload = meetingPayload($this->lead);
    unset($payload['reminders']);
    $this->actingAs($this->org->rahul)->post('/meetings', $payload)->assertSessionHasNoErrors();

    expect(Meeting::sole()->reminder_offsets)->toBe([30]);
});

test('meeting type settings page is restricted to meeting.configure', function () {
    $this->actingAs($this->org->rahul)->get('/admin/meeting-settings')->assertForbidden();
    $this->actingAs($this->org->manager)->get('/admin/meeting-settings')->assertForbidden();
    $this->actingAs($this->org->admin)->get('/admin/meeting-settings')->assertOk();
});

test('an admin can add, edit and deactivate meeting types; used types cannot be deleted', function () {
    $this->actingAs($this->org->admin)->post('/admin/meeting-settings/types', ['name' => 'Factory Tour', 'icon' => 'map-pin', 'color' => 'teal', 'location_mode' => 'physical', 'default_duration_minutes' => 120, 'is_active' => true])->assertSessionHasNoErrors();
    $type = MeetingType::where('name', 'Factory Tour')->sole();

    scheduleMeeting($this->lead, $this->org->rahul, ['meeting_type_id' => $type->id]);
    $this->actingAs($this->org->admin)->delete("/admin/meeting-settings/types/{$type->id}")->assertSessionHasErrors();
    expect($type->fresh())->not->toBeNull();

    $this->actingAs($this->org->admin)->put("/admin/meeting-settings/types/{$type->id}", ['name' => 'Factory Tour', 'icon' => 'map-pin', 'color' => 'teal', 'location_mode' => 'physical', 'default_duration_minutes' => 90, 'is_active' => false])->assertSessionHasNoErrors();
    expect($type->fresh()->is_active)->toBeFalse();

    // Inactive types cannot be used for new meetings.
    $this->actingAs($this->org->rahul)->post('/meetings', meetingPayload($this->lead, ['meeting_type_id' => $type->id, 'start_time' => '14:00', 'end_time' => '15:00']))->assertSessionHasErrors('meeting_type_id');
});
