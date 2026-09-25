<?php

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/*
 * N+1 guards: query counts must not grow with the number of rows.
 */

beforeEach(function () {
    $this->org = salesOrg();
});

function seedFollowups($org, int $count): Lead
{
    $lead = Lead::factory()->assignedTo($org->rahul)->create();
    $others = Lead::factory()->count(4)->assignedTo($org->rahul)->create();

    for ($i = 0; $i < $count; $i++) {
        $target = $i % 5 === 0 ? $lead : $others[$i % 4];
        scheduleFollowup($target, $i % 2 ? $org->admin : $org->rahul, [
            'assigned_to' => $org->rahul->id,
            'scheduled_time' => sprintf('%02d:%02d', 8 + intdiv($i, 60) % 12, $i % 60),
            'followup_type_id' => followupTypeId(['call', 'whatsapp', 'email', 'demo'][$i % 4]),
        ]);
    }

    return $lead;
}

function queryCount(callable $callback): int
{
    $callback(); // warm-up (settings/permission caches)
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('follow-up list query count does not grow with rows', function () {
    seedFollowups($this->org, 25);
    $small = queryCount(fn () => $this->actingAs($this->org->admin)->get('/follow-ups?tab=all&per_page=100')->assertOk());

    seedFollowups($this->org, 75);
    $large = queryCount(fn () => $this->actingAs($this->org->admin)->get('/follow-ups?tab=all&per_page=100')->assertOk());

    expect($large)->toBe($small);
});

test('lead 360 query count does not grow with follow-ups', function () {
    $lead = seedFollowups($this->org, 25);
    $small = queryCount(fn () => $this->actingAs($this->org->admin)->get("/leads/{$lead->id}")->assertOk());

    for ($i = 0; $i < 75; $i++) {
        scheduleFollowup($lead, $this->org->rahul, ['scheduled_date' => now()->addDays(3 + $i)->format('Y-m-d')]);
    }
    $large = queryCount(fn () => $this->actingAs($this->org->admin)->get("/leads/{$lead->id}")->assertOk());

    expect($large)->toBe($small);
});

test('dashboard query count does not grow with follow-ups', function () {
    seedFollowups($this->org, 25);
    $small = queryCount(fn () => $this->actingAs($this->org->admin)->get('/dashboard')->assertOk());

    seedFollowups($this->org, 75);
    $large = queryCount(fn () => $this->actingAs($this->org->admin)->get('/dashboard')->assertOk());

    expect($large)->toBe($small);
});

test('notification endpoints query count does not grow with notifications', function () {
    seedFollowups($this->org, 25); // admin-created rows notify Rahul
    $smallRecent = queryCount(fn () => $this->actingAs($this->org->rahul)->getJson('/notifications/recent')->assertOk());
    $smallIndex = queryCount(fn () => $this->actingAs($this->org->rahul)->get('/notifications')->assertOk());

    seedFollowups($this->org, 75);
    expect($this->org->rahul->notifications()->count())->toBeGreaterThan(40);

    expect(queryCount(fn () => $this->actingAs($this->org->rahul)->getJson('/notifications/recent')->assertOk()))->toBe($smallRecent)
        ->and(queryCount(fn () => $this->actingAs($this->org->rahul)->get('/notifications')->assertOk()))->toBe($smallIndex);
});
