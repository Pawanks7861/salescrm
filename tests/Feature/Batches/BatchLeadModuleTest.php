<?php

use App\Models\Batch;
use App\Models\Lead;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = salesOrg();
    $this->october = Batch::factory()->create(['name' => 'October Campaign']);
    $this->expo = Batch::factory()->create(['name' => 'Expo Visitors']);
    $this->inBoth = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'InBoth']);
    $this->inNone = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'InNone']);
    $this->october->leads()->attach($this->inBoth->id);
    $this->expo->leads()->attach($this->inBoth->id);
});

test('lead and batch relationships work in both directions', function () {
    expect($this->inBoth->batches()->pluck('name')->sort()->values()->all())->toBe(['Expo Visitors', 'October Campaign'])
        ->and($this->october->leads()->pluck('leads.id')->all())->toBe([$this->inBoth->id])
        ->and($this->inNone->batches()->count())->toBe(0);
});

test('the lead list shows each lead\'s batches', function () {
    $rows = collect($this->actingAs($this->org->rahul)->get('/leads')->assertOk()->inertiaProps('leads.data'));

    expect(collect($rows->firstWhere('id', $this->inBoth->id)['batches'])->pluck('name')->all())->toBe(['Expo Visitors', 'October Campaign'])
        ->and($rows->firstWhere('id', $this->inNone->id)['batches'])->toBe([]);
});

test('the lead list batch filter combines with other filters', function () {
    $ids = fn (string $query) => collect($this->actingAs($this->org->rahul)->get('/leads?'.$query)->inertiaProps('leads.data'))->pluck('id')->all();

    expect($ids("batch={$this->october->id}"))->toBe([$this->inBoth->id])
        ->and($ids("batch={$this->october->id}&search=InNone"))->toBe([])
        ->and($ids("batch={$this->october->id}&search=InBoth"))->toBe([$this->inBoth->id]);
});

test('the batch filter cannot widen lead visibility', function () {
    $priyaLead = Lead::factory()->assignedTo($this->org->priya)->create();
    $this->october->leads()->attach($priyaLead->id);

    $ids = collect($this->actingAs($this->org->rahul)->get("/leads?batch={$this->october->id}")->inertiaProps('leads.data'))->pluck('id')->all();

    expect($ids)->toBe([$this->inBoth->id]);
});

test('without batch.view the lead list has no batch column, filter or bulk action', function () {
    $user = setPermission($this->org->rahul, Permissions::BATCH_VIEW, 'deny');

    $response = $this->actingAs($user)->get("/leads?batch={$this->october->id}")->assertOk();
    $rows = collect($response->inertiaProps('leads.data'));

    expect($rows)->toHaveCount(2)
        ->and($rows->first())->not->toHaveKey('batches')
        ->and($response->inertiaProps('options.batches'))->toBe([])
        ->and($response->inertiaProps('can.viewBatches'))->toBeFalse()
        ->and($response->inertiaProps('can.addToBatch'))->toBeFalse();
});

test('Lead 360 lists the lead\'s batches', function () {
    $response = $this->actingAs($this->org->rahul)->get("/leads/{$this->inBoth->id}")->assertOk();

    expect(collect($response->inertiaProps('batches'))->pluck('name')->sort()->values()->all())->toBe(['Expo Visitors', 'October Campaign'])
        ->and($response->inertiaProps('can.manageBatches'))->toBeTrue();

    $denied = setPermission($this->org->priya, Permissions::BATCH_VIEW, 'deny');
    $priyaLead = Lead::factory()->assignedTo($denied)->create();
    expect($this->actingAs($denied)->get("/leads/{$priyaLead->id}")->assertOk()->inertiaProps('batches'))->toBeNull();
});

test('deleting a lead\'s batch keeps the lead and its other memberships', function () {
    $this->actingAs($this->org->admin)->delete("/batches/{$this->october->id}")->assertRedirect();

    expect(Lead::find($this->inBoth->id))->not->toBeNull()
        ->and($this->inBoth->batches()->pluck('name')->all())->toBe(['Expo Visitors']);
});

test('the batch page lists added date and filters by status', function () {
    $won = Lead::factory()->assignedTo($this->org->rahul)->status('won')->create();
    $this->october->leads()->attach($won->id);

    $response = $this->actingAs($this->org->rahul)->get("/batches/{$this->october->id}?status=".leadStatusId('won'))->assertOk();
    $rows = collect($response->inertiaProps('leads.data'));

    expect($rows->pluck('id')->all())->toBe([$won->id])
        ->and($rows->first()['added_at'])->not->toBeNull()
        ->and($response->inertiaProps('summary.total'))->toBe(2);
});

test('the batch page has no N+1 queries as membership grows', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->org->admin)->get("/batches/{$this->october->id}")->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $this->october->leads()->attach(Lead::factory()->count(2)->assignedTo($this->org->rahul)->create()->pluck('id'));
    $small = $count();
    $this->october->leads()->attach(Lead::factory()->count(15)->assignedTo($this->org->priya)->create()->pluck('id'));
    $large = $count();

    expect($large)->toBeLessThanOrEqual($small + 2);
});
