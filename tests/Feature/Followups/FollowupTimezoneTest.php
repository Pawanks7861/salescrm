<?php

use App\Models\Followup;
use App\Models\Lead;
use App\Services\SettingService;
use App\Support\CrmTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

function tabIds($test, string $tab): array
{
    return collect($test->actingAs($test->org->rahul)->get("/follow-ups?tab={$tab}")->inertiaProps('followups.data'))->pluck('id')->all();
}

test('a follow-up exactly at now is due today, not overdue', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00:00', 'Asia/Kolkata'));
    $f = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:00']);

    expect($f->fresh()->isOverdue())->toBeFalse()
        ->and(tabIds($this, 'today'))->toBe([$f->id])
        ->and(tabIds($this, 'overdue'))->toBe([]);
});

test('one minute past is overdue; one minute ahead is not', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Kolkata'));
    $past = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:00']);
    $future = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:02']);

    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:01', 'Asia/Kolkata'));

    expect($past->fresh()->isOverdue())->toBeTrue()
        ->and($future->fresh()->isOverdue())->toBeFalse()
        ->and(tabIds($this, 'overdue'))->toBe([$past->id])
        ->and(tabIds($this, 'today'))->toBe([$future->id]);
});

test('a time one minute in the past is rejected at creation; the current minute is accepted', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00:30', 'Asia/Kolkata'));

    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '09:59']))
        ->assertSessionHasErrors('scheduled_time');
    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:00']))
        ->assertSessionHasNoErrors();
});

test('CRM-local input is stored as UTC and displayed back in CRM time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Kolkata'));
    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['scheduled_date' => '2026-09-24', 'scheduled_time' => '09:15']));

    $raw = DB::table('followups')->value('scheduled_at');
    expect(substr((string) $raw, 0, 19))->toBe('2026-09-24 03:45:00')
        ->and(CrmTime::format(Followup::sole()->scheduled_at))->toBe('Sep 24, 9:15 AM');
});

test('the today tab uses the CRM-local midnight boundary', function () {
    // 23:30 IST on Sep 23 is 18:00 UTC; 00:30 IST on Sep 24 is 19:00 UTC Sep 23 — same UTC date, different CRM days.
    $this->travelTo(CarbonImmutable::parse('2026-09-23 23:00', 'Asia/Kolkata'));
    $beforeMidnight = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '23:30']);
    $afterMidnight = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'scheduled_time' => '00:30']);

    expect(tabIds($this, 'today'))->toBe([$beforeMidnight->id])
        ->and(tabIds($this, 'upcoming'))->toBe([$afterMidnight->id]);

    $this->travelTo(CarbonImmutable::parse('2026-09-24 00:05', 'Asia/Kolkata'));
    expect(tabIds($this, 'today'))->toBe([$afterMidnight->id])
        ->and(tabIds($this, 'overdue'))->toBe([$beforeMidnight->id]);
});

test('the date range filter uses CRM-local days', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Kolkata'));
    $early = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25', 'scheduled_time' => '01:00']);
    scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-26', 'scheduled_time' => '01:00']);

    $ids = collect($this->actingAs($this->org->rahul)->get('/follow-ups?tab=all&from=2026-09-25&to=2026-09-25')->inertiaProps('followups.data'))->pluck('id')->all();
    expect($ids)->toBe([$early->id]);
});

test('DST-safe: nonexistent local times are rejected and wall-clock times survive a DST change', function () {
    app(SettingService::class)->updateGroup('general', ['general.timezone' => 'America/New_York']);
    $this->travelTo(CarbonImmutable::parse('2027-03-10 09:00', 'America/New_York'));

    // 02:30 on 14 Mar 2027 does not exist in New York (clocks jump 02:00 → 03:00).
    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['scheduled_date' => '2027-03-14', 'scheduled_time' => '02:30']))
        ->assertSessionHasErrors(['scheduled_time' => 'Enter a valid date and time.']);

    // 09:00 before (EST, UTC-5) and after (EDT, UTC-4) the change.
    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['scheduled_date' => '2027-03-13', 'scheduled_time' => '09:00']))->assertSessionHasNoErrors();
    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead, ['scheduled_date' => '2027-03-15', 'scheduled_time' => '09:00']))->assertSessionHasNoErrors();

    $rows = Followup::orderBy('scheduled_at')->get();
    expect($rows[0]->scheduled_at->utc()->format('H:i'))->toBe('14:00')
        ->and($rows[1]->scheduled_at->utc()->format('H:i'))->toBe('13:00')
        ->and(CrmTime::format($rows[0]->scheduled_at))->toBe('Mar 13, 9:00 AM')
        ->and(CrmTime::format($rows[1]->scheduled_at))->toBe('Mar 15, 9:00 AM')
        ->and($rows->pluck('timezone')->unique()->all())->toBe(['America/New_York']);
});
