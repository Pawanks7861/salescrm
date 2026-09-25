<?php

use App\Models\AuditLog;
use App\Models\Lead;
use App\Services\ActivityService;

beforeEach(function () {
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

test('lead create and update are audited with changed fields only', function () {
    $this->actingAs($this->org->rahul)->post('/leads', leadPayload(['first_name' => 'Audit']));
    $created = Lead::latest('id')->first();
    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_CREATED', 'entity_id' => $created->id, 'user_id' => $this->org->rahul->id]);

    $this->actingAs($this->org->rahul)->put("/leads/{$this->lead->id}", leadPayload([
        'first_name' => $this->lead->first_name, 'last_name' => $this->lead->last_name, 'phone' => $this->lead->phone,
        'email' => $this->lead->email, 'city' => 'Surat', 'state' => $this->lead->state, 'country' => $this->lead->country,
    ]));

    $log = AuditLog::where('action', 'LEAD_UPDATED')->where('entity_id', $this->lead->id)->sole();
    expect($log->new_values_json)->toHaveKey('city')->and($log->new_values_json['city'])->toBe('Surat')
        ->and($log->old_values_json['city'])->toBe('Ahmedabad')
        ->and($log->new_values_json)->not->toHaveKey('first_name');
});

test('lead views are audited but deduplicated', function () {
    $this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}")->assertOk();
    $this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}")->assertOk();
    $this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}")->assertOk();

    expect(AuditLog::where('action', 'LEAD_VIEWED')->where('entity_id', $this->lead->id)->count())->toBe(1);

    $this->actingAs($this->org->admin)->get("/leads/{$this->lead->id}")->assertOk();
    expect(AuditLog::where('action', 'LEAD_VIEWED')->count())->toBe(2);
});

test('list, search, pipeline and duplicate checks do not create audit noise', function () {
    $before = AuditLog::count();

    $this->actingAs($this->org->rahul)->get('/leads');
    $this->actingAs($this->org->rahul)->get('/leads?search=abc');
    $this->actingAs($this->org->rahul)->getJson('/search/leads?q=abc');
    $this->actingAs($this->org->rahul)->get('/leads/pipeline');
    $this->actingAs($this->org->rahul)->postJson('/leads/duplicate-check', ['phone' => '9999999999']);
    $this->actingAs($this->org->rahul)->getJson("/leads/{$this->lead->id}/activities");

    expect(AuditLog::count())->toBe($before);
});

test('activity timeline is paginated and scoped', function () {
    $activities = app(ActivityService::class);
    foreach (range(1, 25) as $i) {
        $activities->record($this->lead, 'lead_updated', "Change {$i}", [], $this->org->rahul->id);
    }

    $first = $this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}")->inertiaProps('activities');
    expect($first['data'])->toHaveCount(20)->and($first['has_more'])->toBeTrue();

    $next = $this->actingAs($this->org->rahul)->getJson("/leads/{$this->lead->id}/activities?before=".end($first['data'])['id'])->json();
    expect($next['data'])->toHaveCount(5)->and($next['has_more'])->toBeFalse();

    $this->actingAs($this->org->priya)->getJson("/leads/{$this->lead->id}/activities")->assertForbidden();
});
