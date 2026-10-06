<?php

use App\Support\LeadListColumns;

beforeEach(function () {
    $this->org = salesOrg();
});

test('the leads list shows the date column after contact', function () {
    $order = $this->actingAs($this->org->rahul)->get('/leads')->assertOk()->inertiaProps('columnOrder');

    expect(array_slice($order, 0, 3))->toBe(['lead', 'contact', 'created_at'])
        ->and($order)->not->toContain('value');
});

test('a user can rearrange the lead columns and another user keeps the default', function () {
    $default = $this->actingAs($this->org->rahul)->get('/leads')->inertiaProps('columnOrder');
    $custom = $default;
    $date = array_splice($custom, array_search('created_at', $custom, true), 1);
    array_splice($custom, array_search('city', $custom, true), 0, $date);

    $this->actingAs($this->org->rahul)->putJson('/leads/columns', ['columns' => $custom])
        ->assertOk()
        ->assertJsonPath('columns', $custom);

    expect($this->org->rahul->fresh()->lead_list_columns)->toBe($custom)
        ->and($this->actingAs($this->org->rahul)->get('/leads')->inertiaProps('columnOrder'))->toBe($custom)
        ->and($this->actingAs($this->org->priya)->get('/leads')->inertiaProps('columnOrder'))->toBe($default);
});

test('a saved order cannot add, drop or repeat a column', function () {
    $default = LeadListColumns::available(true, false);

    $this->actingAs($this->org->rahul)->putJson('/leads/columns', ['columns' => ['nope']])
        ->assertUnprocessable()->assertJsonValidationErrors('columns');

    $this->actingAs($this->org->rahul)->putJson('/leads/columns', ['columns' => array_slice($default, 1)])
        ->assertUnprocessable();

    $repeated = $default;
    $repeated[1] = $repeated[0];
    $this->actingAs($this->org->rahul)->putJson('/leads/columns', ['columns' => $repeated])
        ->assertUnprocessable();

    expect($this->org->rahul->fresh()->lead_list_columns)->toBeNull();
});

test('resetting columns restores the default for that user only', function () {
    $default = $this->actingAs($this->org->rahul)->get('/leads')->inertiaProps('columnOrder');
    $custom = array_reverse($default);

    $this->actingAs($this->org->rahul)->putJson('/leads/columns', ['columns' => $custom])->assertOk();
    $this->actingAs($this->org->rahul)->putJson('/leads/columns', ['reset' => true])
        ->assertOk()
        ->assertJsonPath('columns', $default);

    expect($this->org->rahul->fresh()->lead_list_columns)->toBeNull();
});
