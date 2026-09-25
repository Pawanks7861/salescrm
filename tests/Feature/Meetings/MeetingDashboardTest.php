<?php

use App\Models\Lead;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->rahulLead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->priyaLead = Lead::factory()->assignedTo($this->org->priya)->create();
});

test('dashboard meeting widgets are scoped: my or company, never team', function () {
    $today = scheduleMeeting($this->rahulLead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'start_time' => '11:00', 'end_time' => '12:00']);
    scheduleMeeting($this->rahulLead, $this->org->rahul, ['scheduled_date' => '2026-09-26']);
    scheduleMeeting($this->priyaLead, $this->org->priya, ['scheduled_date' => '2026-09-24', 'start_time' => '15:00', 'end_time' => '16:00']);
    $outsiderLead = Lead::factory()->assignedTo($this->org->outsider)->create();
    scheduleMeeting($outsiderLead, $this->org->outsider, ['scheduled_date' => '2026-09-24', 'start_time' => '15:00', 'end_time' => '16:00']);

    $rahul = $this->actingAs($this->org->rahul)->get('/dashboard')->inertiaProps('meetings');
    expect($rahul['scope'])->toBe('My')
        ->and($rahul['counts'])->toBe(['today' => 1, 'upcoming' => 2, 'completed_today' => 0])
        ->and($rahul['next']['id'])->toBe($today->id)
        ->and(collect($rahul['today'])->pluck('id')->all())->toBe([$today->id]);

    $manager = $this->actingAs($this->org->manager)->get('/dashboard')->inertiaProps('meetings');
    expect($manager['scope'])->toBe('My')
        ->and($manager['counts']['today'])->toBe(0)
        ->and($manager['counts']['upcoming'])->toBe(0);

    $admin = $this->actingAs($this->org->admin)->get('/dashboard')->inertiaProps('meetings');
    expect($admin['scope'])->toBe('Company')
        ->and($admin['counts']['today'])->toBe(3);
});

test('completed today is counted and closed meetings are not upcoming', function () {
    $m = scheduleMeeting($this->rahulLead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'start_time' => '10:00', 'end_time' => '10:30']);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:45', 'Asia/Kolkata'));
    $this->actingAs($this->org->rahul)->post("/meetings/{$m->id}/complete", ['outcome' => 'interested', 'notes' => 'ok'])->assertSessionHasNoErrors();

    $counts = $this->actingAs($this->org->rahul)->get('/dashboard')->inertiaProps('meetings.counts');
    expect($counts)->toBe(['today' => 0, 'upcoming' => 0, 'completed_today' => 1]);
});
