<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Lead;

beforeEach(function () {
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

function auditActions(): array
{
    return AuditLog::where('module', 'followups')->orderBy('id')->pluck('action')->all();
}

test('every lifecycle step is audited with the actor', function () {
    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead))->assertSessionHasNoErrors();
    $f = $this->lead->followups()->sole();

    $this->actingAs($this->org->rahul)->put("/follow-ups/{$f->id}", ['title' => 'Updated title']);
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$f->id}/reschedule", crmSlot(now()->addDays(3)));
    $new = $this->lead->followups()->where('status', 'pending')->sole();
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$new->id}/complete", ['outcome' => 'connected']);

    $other = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_time' => '17:00']);
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$other->id}/cancel", ['reason' => 'x']);
    $this->actingAs($this->org->admin)->delete("/follow-ups/{$other->id}");
    $this->actingAs($this->org->admin)->post("/follow-ups/{$other->id}/restore");

    expect(auditActions())->toBe([
        AuditAction::FollowupCreated->value,
        AuditAction::FollowupUpdated->value,
        AuditAction::FollowupRescheduled->value,
        AuditAction::FollowupCompleted->value,
        AuditAction::FollowupCreated->value,
        AuditAction::FollowupCancelled->value,
        AuditAction::FollowupDeleted->value,
        AuditAction::FollowupRestored->value,
    ]);

    expect(AuditLog::where('action', AuditAction::FollowupCompleted->value)->sole()->user_id)->toBe($this->org->rahul->id);
});

test('forbidden follow-up access is audited without leaking content', function () {
    $priyaLead = Lead::factory()->assignedTo($this->org->priya)->create();
    $f = scheduleFollowup($priyaLead, $this->org->priya, ['description' => 'Private pricing notes']);

    $this->actingAs($this->org->rahul)->get("/follow-ups/{$f->id}")->assertForbidden();

    $log = AuditLog::where('action', AuditAction::FollowupAccessDenied->value)->sole();
    expect($log->user_id)->toBe($this->org->rahul->id)
        ->and(json_encode($log->toArray()))->not->toContain('Private pricing notes');
});

test('audit entries never contain completion notes', function () {
    $f = scheduleFollowup($this->lead, $this->org->rahul);
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$f->id}/complete", ['outcome' => 'connected', 'notes' => 'Confidential notes']);

    expect(json_encode(AuditLog::all()->toArray()))->not->toContain('Confidential notes');
});
