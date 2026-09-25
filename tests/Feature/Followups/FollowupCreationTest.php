<?php

use App\Enums\FollowupStatus;
use App\Models\Activity;
use App\Models\Followup;
use App\Models\FollowupType;
use App\Models\Lead;
use App\Services\SettingService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->org = salesOrg();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Kolkata'));
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

test('an executive schedules a follow-up on their own lead', function () {
    $this->actingAs($this->org->rahul)
        ->post('/follow-ups', followupPayload($this->lead, ['scheduled_date' => '2026-09-24', 'scheduled_time' => '11:30', 'priority' => 'high', 'description' => 'Discuss pricing']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $f = Followup::sole();
    expect($f->lead_id)->toBe($this->lead->id)
        ->and($f->assigned_to)->toBe($this->org->rahul->id)
        ->and($f->team_id)->toBeNull()
        ->and($f->status)->toBe(FollowupStatus::Pending)
        ->and($f->priority->value)->toBe('high')
        ->and($f->scheduled_at->utc()->toDateTimeString())->toBe('2026-09-24 06:00:00')
        ->and($f->timezone)->toBe('Asia/Kolkata')
        ->and($f->reminder_minutes_before)->toBe(15)
        ->and($f->created_by)->toBe($this->org->rahul->id)
        ->and($f->updated_by)->toBe($this->org->rahul->id);

    expect(Activity::where('subject_id', $this->lead->id)->where('type', 'followup_created')->sole()->description)
        ->toBe('Scheduled a Call follow-up for Sep 24, 11:30 AM');
});

test('client-supplied status, created_by, updated_by and team_id are ignored', function () {
    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, [
        'status' => 'completed',
        'created_by' => $this->org->admin->id,
        'updated_by' => $this->org->admin->id,
        'team_id' => $this->org->otherTeam->id,
        'completed_at' => now()->toDateTimeString(),
    ]))->assertSessionHasNoErrors();

    $f = Followup::sole();
    expect($f->status)->toBe(FollowupStatus::Pending)
        ->and($f->created_by)->toBe($this->org->rahul->id)
        ->and($f->updated_by)->toBe($this->org->rahul->id)
        ->and($f->team_id)->toBeNull()
        ->and($f->completed_at)->toBeNull();
});

test('required fields and formats are validated', function () {
    $this->actingAs($this->org->rahul)->post('/follow-ups', [])
        ->assertSessionHasErrors(['lead_id', 'followup_type_id', 'scheduled_date', 'scheduled_time']);

    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['scheduled_date' => '24/09/2026', 'scheduled_time' => '25:00', 'priority' => 'critical']))
        ->assertSessionHasErrors(['scheduled_date', 'scheduled_time', 'priority']);

    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['followup_type_id' => 99999]))
        ->assertSessionHasErrors('followup_type_id');

    expect(Followup::count())->toBe(0);
});

test('an unknown lead id returns 404', function () {
    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['lead_id' => 999999]))->assertNotFound();
});

test('inactive types cannot be used for new follow-ups', function () {
    FollowupType::where('slug', 'demo')->update(['is_active' => false]);

    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['followup_type_id' => followupTypeId('demo')]))
        ->assertSessionHasErrors('followup_type_id');
});

test('past times are rejected, not silently corrected', function () {
    $this->actingAs($this->org->rahul)
        ->post('/follow-ups', followupPayload($this->lead, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '09:59']))
        ->assertSessionHasErrors(['scheduled_time' => 'Follow-ups cannot be scheduled in the past.']);

    $this->actingAs($this->org->rahul)
        ->post('/follow-ups', followupPayload($this->lead, ['scheduled_date' => '2026-09-22', 'scheduled_time' => '11:00']))
        ->assertSessionHasErrors('scheduled_time');

    expect(Followup::count())->toBe(0);
});

test('past times are allowed with followup.schedule_past or the allow_past setting', function () {
    $this->actingAs($this->org->admin)
        ->post('/follow-ups', followupPayload($this->lead, ['scheduled_date' => '2026-09-22', 'scheduled_time' => '11:00']))
        ->assertSessionHasNoErrors();

    app(SettingService::class)->updateGroup('followup', ['followup.allow_past' => true]);
    $this->actingAs($this->org->rahul)
        ->post('/follow-ups', followupPayload($this->lead, ['scheduled_date' => '2026-09-22', 'scheduled_time' => '12:00']))
        ->assertSessionHasNoErrors();

    expect(Followup::count())->toBe(2)
        ->and(Followup::all()->every->isOverdue())->toBeTrue();
});

test('a nearby duplicate for the same lead and assignee needs confirmation', function () {
    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['scheduled_time' => '11:00']))->assertSessionHasNoErrors();

    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['scheduled_time' => '11:04']))
        ->assertSessionHasErrors('duplicate');
    expect(Followup::count())->toBe(1);

    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['scheduled_time' => '11:04', 'confirm_duplicate' => true]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['scheduled_time' => '11:30']))
        ->assertSessionHasNoErrors();

    expect(Followup::count())->toBe(3);
});

test('default reminder comes from settings and "no reminder" is respected', function () {
    app(SettingService::class)->updateGroup('followup', ['followup.default_reminder_minutes' => 60]);

    $this->actingAs($this->org->rahul)->post('/follow-ups', collect(followupPayload($this->lead))->except('reminder_minutes')->all())->assertSessionHasNoErrors();
    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['reminder_minutes' => null, 'scheduled_time' => '15:00']))->assertSessionHasNoErrors();

    $rows = Followup::orderBy('id')->get();
    expect($rows[0]->reminder_minutes_before)->toBe(60)
        ->and($rows[1]->reminder_minutes_before)->toBeNull();

    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['reminder_minutes' => 20000, 'scheduled_time' => '17:00']))
        ->assertSessionHasErrors('reminder_minutes');
});

test('an admin scheduling on someone else\'s lead defaults the assignee to the lead owner', function () {
    $this->actingAs($this->org->admin)->post('/follow-ups', followupPayload($this->lead))->assertSessionHasNoErrors();

    expect(Followup::sole()->assigned_to)->toBe($this->org->rahul->id);
});

test('a legacy team manager cannot schedule on a member lead', function () {
    $this->actingAs($this->org->manager)->post('/follow-ups', followupPayload($this->lead))->assertForbidden();

    expect(Followup::count())->toBe(0);
});

test('an admin may assign only to users who can see the lead', function () {
    // Priya and Outside Exec cannot see Rahul's lead.
    foreach ([$this->org->outsider, $this->org->priya] as $user) {
        $this->actingAs($this->org->admin)->post('/follow-ups', followupPayload($this->lead, ['assigned_to' => $user->id]))
            ->assertSessionHasErrors('assigned_to');
    }

    $this->actingAs($this->org->admin)->post('/follow-ups', followupPayload($this->lead, ['assigned_to' => $this->org->admin->id]))
        ->assertSessionHasNoErrors();

    expect(Followup::sole()->assigned_to)->toBe($this->org->admin->id);
});

test('follow-ups cannot be created on an archived lead', function () {
    $this->lead->delete();

    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead))->assertNotFound();
    expect(Followup::count())->toBe(0);
});
