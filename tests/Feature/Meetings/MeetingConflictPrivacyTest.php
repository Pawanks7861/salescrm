<?php

use App\Models\Lead;
use App\Models\Meeting;
use Carbon\CarbonImmutable;

/*
 * RELEASE-BLOCKING (§68/§72). A conflict with a meeting the actor cannot see
 * says only that the person is unavailable — no title, lead, notes, time or
 * other participants.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 08:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->rahulLead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->priyaLead = Lead::factory()->assignedTo($this->org->priya)->create(['first_name' => 'Secret', 'last_name' => 'Customer']);

    // The admin joins Priya's confidential meeting 15:00–16:00.
    $this->hidden = scheduleMeeting($this->priyaLead, $this->org->priya, [
        'title' => 'Confidential pricing',
        'agenda' => 'Discount strategy',
        'scheduled_date' => '2026-09-25',
        'start_time' => '15:00',
        'end_time' => '16:00',
        'participant_user_ids' => [$this->org->admin->id],
    ]);
});

test('rahul inviting a busy admin learns only that they are unavailable', function () {
    $response = $this->actingAs($this->org->rahul)->post('/meetings', meetingPayload($this->rahulLead, [
        'scheduled_date' => '2026-09-25',
        'start_time' => '15:30',
        'end_time' => '16:30',
        'participant_user_ids' => [$this->org->admin->id],
    ]));

    $response->assertSessionHasErrors(['conflicts.0' => 'Anita Admin is unavailable during the selected time.']);

    $errors = collect(session('errors')->getBag('default')->all())->implode(' ');
    expect($errors)->not->toContain('Confidential pricing')
        ->and($errors)->not->toContain('Secret Customer')
        ->and($errors)->not->toContain('Discount strategy')
        ->and($errors)->not->toContain('Priya')
        ->and($errors)->not->toContain('3:00 PM')
        ->and($errors)->not->toContain($this->hidden->meeting_number)
        ->and(Meeting::count())->toBe(1);
});

test('rahul adding the busy admin to an existing meeting gets the same private message', function () {
    $mine = scheduleMeeting($this->rahulLead, $this->org->rahul, ['scheduled_date' => '2026-09-25', 'start_time' => '15:30', 'end_time' => '16:30']);

    $this->actingAs($this->org->rahul)
        ->post("/meetings/{$mine->id}/participants", ['type' => 'user', 'user_id' => $this->org->admin->id])
        ->assertSessionHasErrors(['conflicts.0' => 'Anita Admin is unavailable during the selected time.']);
});

test('a viewer who can see the conflicting meeting gets the details', function () {
    // The admin can see Priya's meeting (company scope) and is told exactly what clashes.
    $this->actingAs($this->org->admin)
        ->post('/meetings', meetingPayload($this->priyaLead, ['scheduled_date' => '2026-09-25', 'start_time' => '15:30', 'end_time' => '16:30', 'host_user_id' => $this->org->priya->id]))
        ->assertSessionHasErrors(['conflicts.0' => "Priya Patel already has another meeting from 3:00 PM to 4:00 PM on Sep 25 ({$this->hidden->meeting_number}: Confidential pricing)."]);
});
