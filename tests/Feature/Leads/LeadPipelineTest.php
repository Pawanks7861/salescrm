<?php

use App\Models\Lead;
use App\Models\LeadStatus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = salesOrg();
    Lead::factory()->count(3)->assignedTo($this->org->rahul)->create();
    Lead::factory()->count(2)->assignedTo($this->org->priya)->status('contacted')->create();
    Lead::factory()->assignedTo($this->org->outsider)->create();
});

function pipelineCounts($test, $user): array
{
    return collect($test->actingAs($user)->get('/leads/pipeline')->assertOk()->inertiaProps('columns'))
        ->mapWithKeys(fn ($c) => [$c['status']['name'] => $c['count']])->all();
}

test('columns follow active statuses in order', function () {
    LeadStatus::where('slug', 'follow_up')->update(['is_active' => false]);

    $names = collect($this->actingAs($this->org->admin)->get('/leads/pipeline')->inertiaProps('columns'))->pluck('status.name')->all();

    expect($names)->not->toContain('Follow-up')->and($names[0])->toBe('New');
});

test('pipeline is visibility scoped', function () {
    expect(pipelineCounts($this, $this->org->rahul))->toMatchArray(['New' => 3, 'Contacted' => 0]);
    expect(pipelineCounts($this, $this->org->manager))->toMatchArray(['New' => 0, 'Contacted' => 0]);
    expect(pipelineCounts($this, $this->org->admin))->toMatchArray(['New' => 4, 'Contacted' => 2]);
});

test('load more returns only visible cards', function () {
    $cards = $this->actingAs($this->org->rahul)->getJson('/leads/pipeline/'.leadStatusId('contacted').'/more?offset=0')->assertOk()->json('cards');

    expect($cards)->toBe([]);
});

test('drag to lost without a reason is rejected', function () {
    $lead = Lead::where('assigned_to', $this->org->rahul->id)->first();

    $this->actingAs($this->org->rahul)->post("/leads/{$lead->id}/status", ['status_id' => leadStatusId('lost')])->assertSessionHasErrors('lost_reason_id');
});

test('dragging another users card is forbidden', function () {
    $lead = Lead::where('assigned_to', $this->org->priya->id)->first();

    $this->actingAs($this->org->rahul)->post("/leads/{$lead->id}/status", ['status_id' => leadStatusId('interested')])->assertForbidden();
});

test('pipeline query count stays bounded (no N+1)', function () {
    Lead::factory()->count(20)->assignedTo($this->org->rahul)->create();

    DB::enableQueryLog();
    $this->actingAs($this->org->manager)->get('/leads/pipeline')->assertOk();
    $count = count(DB::getQueryLog());

    expect($count)->toBeLessThan(60);
});

test('lead list query count does not grow with page size (no N+1)', function () {
    Lead::factory()->count(30)->assignedTo($this->org->rahul)->create();

    $measure = function (int $perPage) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->org->admin)->get("/leads?per_page={$perPage}")->assertOk();

        return count(DB::getQueryLog());
    };

    $measure(25);

    expect($measure(100))->toBe($measure(25));
});
