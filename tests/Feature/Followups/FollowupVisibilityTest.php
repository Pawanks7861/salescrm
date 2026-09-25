<?php

use App\Models\Followup;
use App\Models\Lead;
use App\Services\Followups\FollowupVisibility;

beforeEach(function () {
    $this->org = salesOrg();
    $this->rahulLead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->priyaLead = Lead::factory()->assignedTo($this->org->priya)->create();
    $this->outsiderLead = Lead::factory()->assignedTo($this->org->outsider)->create();

    $this->rahulF = scheduleFollowup($this->rahulLead, $this->org->rahul);
    $this->priyaF = scheduleFollowup($this->priyaLead, $this->org->priya);
    $this->outsiderF = scheduleFollowup($this->outsiderLead, $this->org->outsider);
});

function visibleFollowupIds($user)
{
    return Followup::query()->visibleTo($user)->pluck('id')->sort()->values()->all();
}

test('tiers: own and all only', function () {
    $v = app(FollowupVisibility::class);

    expect($v->tier($this->org->rahul))->toBe(FollowupVisibility::OWN)
        ->and($v->tier($this->org->manager))->toBe(FollowupVisibility::OWN)
        ->and($v->tier($this->org->admin))->toBe(FollowupVisibility::ALL)
        ->and($v->tier($this->org->super))->toBe(FollowupVisibility::ALL);
});

test('scoped queries return only what each role may see', function () {
    expect(visibleFollowupIds($this->org->rahul))->toBe([$this->rahulF->id])
        ->and(visibleFollowupIds($this->org->priya))->toBe([$this->priyaF->id])
        ->and(visibleFollowupIds($this->org->manager))->toBe([])
        ->and(visibleFollowupIds($this->org->otherManager))->toBe([])
        ->and(visibleFollowupIds($this->org->admin))->toBe([$this->rahulF->id, $this->priyaF->id, $this->outsiderF->id]);
});

test('an own follow-up on a lead the user can no longer see is hidden (lead visibility is never bypassed)', function () {
    $this->rahulF->forceFill(['lead_id' => $this->outsiderLead->id])->save();

    expect(visibleFollowupIds($this->org->rahul))->toBe([])
        ->and(app(FollowupVisibility::class)->canView($this->org->rahul, $this->rahulF->fresh()))->toBeFalse();
});

test('follow-ups on archived leads are hidden from everyone, including the list', function () {
    $this->rahulLead->delete();

    expect(visibleFollowupIds($this->org->rahul))->toBe([])
        ->and(visibleFollowupIds($this->org->admin))->not->toContain($this->rahulF->id);
    $this->actingAs($this->org->rahul)->get("/follow-ups/{$this->rahulF->id}")->assertForbidden();
});

test('legacy team_id on a follow-up grants the old team manager nothing', function () {
    $f = $this->rahulF->fresh()->load('lead');
    $f->forceFill(['team_id' => $this->org->team->id])->save();

    expect(app(FollowupVisibility::class)->canView($this->org->manager, $f))->toBeFalse()
        ->and(visibleFollowupIds($this->org->manager))->not->toContain($f->id);
    $this->actingAs($this->org->manager)->get("/follow-ups/{$f->id}")->assertForbidden();
});

test('the lead 360 follow-ups tab only lists follow-ups the viewer may see', function () {
    $adminOwn = scheduleFollowup($this->rahulLead, $this->org->admin, ['assigned_to' => $this->org->admin->id, 'scheduled_time' => '16:00']);

    // Rahul owns the lead, so every follow-up on it is his to see.
    $rahulSees = collect($this->actingAs($this->org->rahul)->get("/leads/{$this->rahulLead->id}")->inertiaProps('followups'))->pluck('id');
    expect($rahulSees->sort()->values()->all())->toBe([$this->rahulF->id, $adminOwn->id]);

    $this->actingAs($this->org->priya)->get("/leads/{$this->rahulLead->id}")->assertForbidden();
});

test('list rows never include completion notes', function () {
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$this->rahulF->id}/complete", ['outcome' => 'connected', 'notes' => 'Top secret discussion']);

    $body = $this->actingAs($this->org->admin)->get('/follow-ups?tab=completed')->getContent();
    expect($body)->not->toContain('Top secret discussion');

    $this->actingAs($this->org->admin)->get("/follow-ups/{$this->rahulF->id}")->assertOk()
        ->assertSee('Top secret discussion', false);
});
