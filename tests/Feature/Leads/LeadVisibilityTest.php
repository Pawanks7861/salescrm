<?php

use App\Models\Lead;
use App\Services\Leads\LeadVisibility;

beforeEach(function () {
    $this->org = salesOrg();
    $this->rahulLead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'RahulLead']);
    $this->priyaLead = Lead::factory()->assignedTo($this->org->priya)->create(['first_name' => 'PriyaLead']);
    $this->outsiderLead = Lead::factory()->assignedTo($this->org->outsider)->create(['first_name' => 'OutsiderLead']);
    $this->queued = Lead::factory()->create(['first_name' => 'QueuedLead', 'assigned_to' => null, 'team_id' => $this->org->team->id]);
    $this->orphan = Lead::factory()->create(['first_name' => 'OrphanLead', 'assigned_to' => null, 'team_id' => null]);
});

function visibleIds($test, $user): array
{
    return collect($test->actingAs($user)->get('/leads')->assertOk()->inertiaProps('leads.data'))->pluck('id')->sort()->values()->all();
}

test('sales executive only sees own leads', function () {
    expect(visibleIds($this, $this->org->rahul))->toBe([$this->rahulLead->id]);
});

test('a manager gets no team visibility: no member leads and no legacy team queue', function () {
    expect(visibleIds($this, $this->org->manager))->toBe([])
        ->and(visibleIds($this, $this->org->otherManager))->toBe([]);

    foreach ([$this->rahulLead, $this->priyaLead, $this->queued] as $lead) {
        $this->actingAs($this->org->manager)->get("/leads/{$lead->id}")->assertForbidden();
    }
});

test('unassigned leads, with or without a legacy team, are visible to admins only', function () {
    foreach ([$this->org->rahul, $this->org->priya, $this->org->manager, $this->org->outsider] as $user) {
        expect(visibleIds($this, $user))->not->toContain($this->queued->id, $this->orphan->id);
    }
    expect(visibleIds($this, $this->org->admin))->toContain($this->queued->id, $this->orphan->id);
});

test('admin and super admin see all leads', function () {
    expect(visibleIds($this, $this->org->admin))->toHaveCount(5);
    expect(visibleIds($this, $this->org->super))->toHaveCount(5);
});

test('scope and in-memory check agree for every user and lead', function () {
    $visibility = app(LeadVisibility::class);

    foreach ([$this->org->rahul, $this->org->priya, $this->org->manager, $this->org->otherManager, $this->org->outsider, $this->org->admin, $this->org->super] as $user) {
        $sql = Lead::query()->visibleTo($user)->pluck('id')->all();
        foreach (Lead::all() as $lead) {
            expect($visibility->canView($user, $lead))->toBe(in_array($lead->id, $sql, true), "{$user->name} / {$lead->first_name}");
        }
    }
});

test('filters cannot widen visibility', function () {
    $ids = collect($this->actingAs($this->org->rahul)
        ->get('/leads?assignee='.$this->org->priya->id.'&team='.$this->org->team->id)
        ->inertiaProps('leads.data'))->pluck('id')->all();

    expect($ids)->toBe([]);
});

test('user filter options never list users outside visibility', function () {
    $admin = collect($this->actingAs($this->org->admin)->get('/leads')->inertiaProps('options.users'))->pluck('id');
    expect($admin)->toContain($this->org->rahul->id, $this->org->priya->id, $this->org->outsider->id);

    expect($this->actingAs($this->org->manager)->get('/leads')->inertiaProps('options.users'))->toBe([])
        ->and($this->actingAs($this->org->rahul)->get('/leads')->inertiaProps('options.users'))->toBe([]);
});

test('global search is visibility scoped', function () {
    $this->actingAs($this->org->rahul)->getJson('/search/leads?q=PriyaLead')->assertOk()->assertJsonCount(0, 'results');
    $this->actingAs($this->org->rahul)->getJson('/search/leads?q=RahulLead')->assertOk()->assertJsonCount(1, 'results');
});

test('lead list supports status, priority, unassigned and age filters in SQL', function () {
    $this->rahulLead->forceFill(['priority' => 'urgent'])->save();
    Lead::whereKey($this->priyaLead->id)->update(['created_at' => now()->subDays(20)]);

    $ids = fn ($q) => collect($this->actingAs($this->org->admin)->get('/leads?'.$q)->inertiaProps('leads.data'))->pluck('id')->all();

    expect($ids('priority=urgent'))->toBe([$this->rahulLead->id]);
    expect($ids('assignee=unassigned'))->toContain($this->queued->id, $this->orphan->id)->toHaveCount(2);
    expect($ids('age=15%2B'))->toBe([$this->priyaLead->id]);
    expect($ids('status='.leadStatusId('won')))->toBe([]);
});

test('search supports lead number prefix, phone and name', function () {
    $this->rahulLead->forceFill(['lead_number' => 'LD-2026-000777', 'phone' => '9824011111', 'normalized_phone' => '919824011111', 'full_name' => 'Amit Desai'])->save();

    $search = fn ($q) => collect($this->actingAs($this->org->admin)->get('/leads?search='.urlencode($q))->inertiaProps('leads.data'))->pluck('id')->all();

    expect($search('LD-2026-000777'))->toBe([$this->rahulLead->id]);
    expect($search('LD-2026-0007'))->toBe([$this->rahulLead->id]);
    expect($search('+91 98240 11111'))->toBe([$this->rahulLead->id]);
    expect($search('98240 11111'))->toBe([$this->rahulLead->id]);
    expect($search('011111'))->toBe([$this->rahulLead->id]);
    expect($search('Amit'))->toBe([$this->rahulLead->id]);
    expect($search('%'))->toBe([]);
});
