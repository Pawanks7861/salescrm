<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Lead;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

test('the meeting lifecycle is fully audited', function () {
    $m = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'start_time' => '10:00', 'end_time' => '11:00']);
    $this->actingAs($this->org->rahul)->put("/meetings/{$m->id}", ['title' => 'Renamed demo'])->assertSessionHasNoErrors();
    $this->actingAs($this->org->rahul)->post("/meetings/{$m->id}/confirm")->assertSessionHasNoErrors();
    $this->actingAs($this->org->rahul)->post("/meetings/{$m->id}/reschedule", ['scheduled_date' => '2026-09-24', 'start_time' => '12:00', 'end_time' => '13:00'])->assertSessionHasNoErrors();
    $new = $m->fresh()->rescheduledTo;
    $this->actingAs($this->org->rahul)->post("/meetings/{$new->id}/start")->assertSessionHasNoErrors();
    $this->travelTo(CarbonImmutable::parse('2026-09-24 13:10', 'Asia/Kolkata'));
    $this->actingAs($this->org->rahul)->post("/meetings/{$new->id}/complete", ['outcome' => 'interested', 'notes' => 'ok'])->assertSessionHasNoErrors();

    $actions = AuditLog::where('module', 'meetings')->pluck('action')->all();
    foreach ([AuditAction::MeetingCreated, AuditAction::MeetingUpdated, AuditAction::MeetingConfirmed, AuditAction::MeetingRescheduled, AuditAction::MeetingStarted, AuditAction::MeetingCompleted] as $action) {
        expect($actions)->toContain($action->value);
    }

    $update = AuditLog::where('action', AuditAction::MeetingUpdated->value)->sole();
    expect($update->old_values_json)->toBe(['title' => 'Product demo'])
        ->and($update->new_values_json)->toBe(['title' => 'Renamed demo'])
        ->and($update->user_id)->toBe($this->org->rahul->id);
});

test('audit entries never contain meeting links or participant contact details', function () {
    scheduleMeeting($this->lead, $this->org->rahul, [
        'scheduled_date' => '2026-09-25',
        'meeting_url' => 'https://meet.example.com/secret-room',
        'external_participants' => [['name' => 'Guest', 'email' => 'guest@example.com', 'phone' => '9000011111']],
    ]);

    $json = AuditLog::where('module', 'meetings')->get()->toJson();
    expect($json)->not->toContain('secret-room')
        ->and($json)->not->toContain('guest@example.com')
        ->and($json)->not->toContain('9000011111');
});
