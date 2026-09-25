<?php

use App\Models\Followup;
use App\Models\Lead;
use App\Models\Permission;
use App\Services\PermissionRegistrar;
use App\Support\Permissions;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->org = salesOrg();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Kolkata'));
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->priyaLead = Lead::factory()->assignedTo($this->org->priya)->create();

    scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:30']); // overdue later
    scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '18:00']); // today
    scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25', 'scheduled_time' => '11:00']); // upcoming
    $done = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:45']);
    scheduleFollowup($this->priyaLead, $this->org->priya, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:15']);

    $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00', 'Asia/Kolkata'));
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$done->id}/complete", ['outcome' => 'connected']);
});

test('sales dashboard counts are derived at read time and scoped to the user', function () {
    $sales = $this->actingAs($this->org->rahul)->get('/dashboard')->assertOk()->inertiaProps('sales');

    expect($sales['counts'])->toMatchArray(['overdue' => 1, 'today' => 1, 'upcoming' => 1, 'completed_today' => 1])
        ->and($sales['overdue'])->toHaveCount(1)
        ->and($sales['today'])->toHaveCount(1);
});

test('a legacy team manager dashboard shows only their own follow-ups; admin sees the company', function () {
    $manager = $this->actingAs($this->org->manager)->get('/dashboard')->inertiaProps('sales');
    expect($manager['counts']['overdue'])->toBe(0)
        ->and($manager['counts']['today'])->toBe(0);

    $admin = $this->actingAs($this->org->admin)->get('/dashboard')->inertiaProps('sales');
    expect($admin['counts']['overdue'])->toBe(2)
        ->and($admin['counts']['today'])->toBe(1);
});

test('overdue counts move with the clock without any stored status change', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:01', 'Asia/Kolkata'));
    $sales = $this->actingAs($this->org->rahul)->get('/dashboard')->inertiaProps('sales');

    expect($sales['counts']['overdue'])->toBe(2)
        ->and($sales['counts']['today'])->toBe(0);
    expect(Followup::where('status', 'overdue')->count())->toBe(0);
});

test('list tab counts match the dashboard', function () {
    $counts = $this->actingAs($this->org->rahul)->get('/follow-ups')->inertiaProps('counts');

    expect($counts['overdue'])->toBe(1)
        ->and($counts['today'])->toBe(1)
        ->and($counts['upcoming'])->toBe(1);
});

test('users without follow-up permissions get no follow-up widgets', function () {
    $this->org->rahul->permissionOverrides()->attach(Permission::where('name', Permissions::FOLLOWUP_VIEW)->value('id'), ['type' => 'deny']);
    app(PermissionRegistrar::class)->flushUser($this->org->rahul);

    expect($this->actingAs($this->org->rahul)->get('/dashboard')->assertOk()->inertiaProps('sales'))->toBeNull();
});
