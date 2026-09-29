<?php

use App\Models\Lead;
use App\Support\CrmTime;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->org = salesOrg();
});

test('leads can be listed for a single calendar day', function () {
    $today = CarbonImmutable::now(CrmTime::tz())->toDateString();
    $fresh = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Today']);
    $older = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Older']);
    $older->forceFill(['created_at' => now()->subDays(4), 'updated_at' => now()->subDays(4)])->save();

    $ids = collect($this->actingAs($this->org->rahul)->get('/leads?on='.$today)->viewData('page')['props']['leads']['data'])->pluck('id');

    expect($ids->all())->toContain($fresh->id)->not->toContain($older->id);
});

test('searching the real id finds that lead', function () {
    $lead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Real', 'phone' => '9811100001']);
    $other = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Other', 'phone' => '9811100002']);

    $ids = collect($this->actingAs($this->org->rahul)->get('/leads?search='.$lead->id)->viewData('page')['props']['leads']['data'])->pluck('id');

    expect($ids->all())->toBe([$lead->id])->not->toContain($other->id);

    if ($lead->id >= 10) {
        $found = $this->actingAs($this->org->rahul)->getJson('/search/leads?q='.$lead->id)->assertOk()->json('results');
        expect(collect($found)->pluck('id')->all())->toContain($lead->id);
    }
});
