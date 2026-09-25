<?php

use App\Enums\FollowupStatus;
use App\Models\Followup;
use App\Models\Lead;

/*
 * RELEASE-BLOCKING. Rahul and Priya are executives on the same team. Rahul
 * must never read, change or discover Priya's follow-ups or her lead data
 * through any follow-up path.
 */

beforeEach(function () {
    $this->org = salesOrg();
    $this->rahulLead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
    $this->priyaLead = Lead::factory()->assignedTo($this->org->priya)->create(['first_name' => 'Secret', 'last_name' => 'Customer', 'phone' => '9811122233']);
    $this->rahulFollowup = scheduleFollowup($this->rahulLead, $this->org->rahul, ['title' => 'Rahul call']);
    $this->priyaFollowup = scheduleFollowup($this->priyaLead, $this->org->priya, ['title' => 'Priya confidential call', 'description' => 'Private pricing notes']);
});

test('rahul cannot view priya\'s follow-up by direct numeric id', function () {
    $this->actingAs($this->org->rahul)->get("/follow-ups/{$this->priyaFollowup->id}")->assertForbidden();
});

test('rahul cannot edit, complete, reschedule, cancel, delete or restore priya\'s follow-up', function () {
    $id = $this->priyaFollowup->id;
    $rahul = $this->org->rahul;

    $this->actingAs($rahul)->put("/follow-ups/{$id}", ['title' => 'hijacked'])->assertForbidden();
    $this->actingAs($rahul)->post("/follow-ups/{$id}/complete", ['outcome' => 'connected'])->assertForbidden();
    $this->actingAs($rahul)->post("/follow-ups/{$id}/reschedule", crmSlot(now()->addDays(3)))->assertForbidden();
    $this->actingAs($rahul)->post("/follow-ups/{$id}/cancel", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($rahul)->delete("/follow-ups/{$id}")->assertForbidden();
    $this->actingAs($rahul)->post("/follow-ups/{$id}/restore")->assertForbidden();

    $fresh = $this->priyaFollowup->fresh();
    expect($fresh->title)->toBe('Priya confidential call')
        ->and($fresh->status)->toBe(FollowupStatus::Pending)
        ->and(Followup::count())->toBe(2);
});

test('priya\'s follow-up never appears in rahul\'s list, tabs or search', function () {
    foreach (['due', 'today', 'overdue', 'upcoming', 'completed', 'cancelled', 'all'] as $tab) {
        $ids = collect($this->actingAs($this->org->rahul)->get("/follow-ups?tab={$tab}")->inertiaProps('followups.data'))->pluck('id');
        expect($ids)->not->toContain($this->priyaFollowup->id);
    }

    foreach (['Secret', 'Customer', '9811122233', 'Priya confidential', $this->priyaLead->lead_number] as $term) {
        $response = $this->actingAs($this->org->rahul)->get('/follow-ups?tab=all&search='.urlencode($term));
        expect($response->inertiaProps('followups.data'))->toBeEmpty();
    }

    $this->actingAs($this->org->rahul)->get("/follow-ups?tab=all&lead={$this->priyaLead->id}")
        ->assertOk();
    expect($this->actingAs($this->org->rahul)->get("/follow-ups?tab=all&lead={$this->priyaLead->id}")->inertiaProps('followups.data'))->toBeEmpty();
});

test('counts and dashboard do not include priya\'s follow-ups for rahul', function () {
    $counts = $this->actingAs($this->org->rahul)->get('/follow-ups')->inertiaProps('counts');
    expect($counts['upcoming'])->toBe(1);

    $sales = $this->actingAs($this->org->rahul)->get('/dashboard')->inertiaProps('sales');
    expect($sales['counts']['upcoming'])->toBe(1);
});

test('rahul cannot reach priya\'s lead through the lead route or lead follow-up tab', function () {
    $this->actingAs($this->org->rahul)->get("/leads/{$this->priyaLead->id}")->assertForbidden();
    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->priyaLead))->assertForbidden();
    expect(Followup::where('lead_id', $this->priyaLead->id)->count())->toBe(1);
});

test('rahul cannot assign a follow-up to priya by manipulating assigned_to', function () {
    $this->actingAs($this->org->rahul)
        ->post('/follow-ups', followupPayload($this->rahulLead, ['assigned_to' => $this->org->priya->id, 'scheduled_time' => '15:00']))
        ->assertSessionHasErrors('assigned_to');

    $this->actingAs($this->org->rahul)
        ->put("/follow-ups/{$this->rahulFollowup->id}", ['assigned_to' => $this->org->priya->id])
        ->assertSessionHasErrors('assigned_to');

    expect($this->rahulFollowup->fresh()->assigned_to)->toBe($this->org->rahul->id);
});

test('follow-up responses never leak priya\'s lead data to rahul', function () {
    $pages = [
        $this->actingAs($this->org->rahul)->get('/follow-ups?tab=all'),
        $this->actingAs($this->org->rahul)->get('/dashboard'),
        $this->actingAs($this->org->rahul)->get("/leads/{$this->rahulLead->id}"),
        $this->actingAs($this->org->rahul)->get("/follow-ups/{$this->rahulFollowup->id}"),
        $this->actingAs($this->org->rahul)->getJson('/notifications/recent'),
    ];

    foreach ($pages as $response) {
        $body = $response->getContent();
        expect($body)->not->toContain('Secret Customer')
            ->and($body)->not->toContain('9811122233')
            ->and($body)->not->toContain('Priya confidential call')
            ->and($body)->not->toContain('Private pricing notes');
    }
});

test('a follow-up stays hidden when its lead moves out of the assignee\'s visibility', function () {
    // Rahul's follow-up remains assigned to him, but the lead is reassigned to Priya.
    $this->rahulLead->forceFill(['assigned_to' => $this->org->priya->id])->save();

    $this->actingAs($this->org->rahul)->get("/follow-ups/{$this->rahulFollowup->id}")->assertForbidden();
    expect(collect($this->actingAs($this->org->rahul)->get('/follow-ups?tab=all')->inertiaProps('followups.data'))->pluck('id'))
        ->not->toContain($this->rahulFollowup->id);
});

test('a legacy team manager sees no member follow-ups; admin and super admin see all', function () {
    $outsiderLead = Lead::factory()->assignedTo($this->org->outsider)->create();
    $outsiderFollowup = scheduleFollowup($outsiderLead, $this->org->outsider);

    $managerIds = collect($this->actingAs($this->org->manager)->get('/follow-ups?tab=all')->inertiaProps('followups.data'))->pluck('id');
    expect($managerIds->all())->toBe([]);
    foreach ([$this->rahulFollowup, $this->priyaFollowup, $outsiderFollowup] as $f) {
        $this->actingAs($this->org->manager)->get("/follow-ups/{$f->id}")->assertForbidden();
    }

    foreach ([$this->org->admin, $this->org->super] as $user) {
        $ids = collect($this->actingAs($user)->get('/follow-ups?tab=all')->inertiaProps('followups.data'))->pluck('id');
        expect($ids)->toContain($this->rahulFollowup->id, $this->priyaFollowup->id, $outsiderFollowup->id);
        $this->actingAs($user)->get("/follow-ups/{$outsiderFollowup->id}")->assertOk();
    }
});

test('unknown follow-up ids return 404 and non-numeric ids do not route', function () {
    $this->actingAs($this->org->rahul)->get('/follow-ups/999999')->assertNotFound();
    $this->actingAs($this->org->rahul)->get('/follow-ups/export')->assertNotFound();
});
