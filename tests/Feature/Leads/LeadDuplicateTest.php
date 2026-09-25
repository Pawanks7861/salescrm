<?php

use App\Enums\AssignmentType;
use App\Models\Lead;
use App\Services\Leads\LeadService;
use App\Services\SettingService;

beforeEach(function () {
    $this->org = salesOrg();
    $this->existing = Lead::factory()->assignedTo($this->org->rahul)->create([
        'phone' => '9824011111', 'normalized_phone' => '919824011111', 'email' => 'amit@example.com',
    ]);
});

test('duplicate check returns visible matches with differently formatted phone', function () {
    $this->actingAs($this->org->rahul)
        ->postJson('/leads/duplicate-check', ['phone' => '+91 98240-11111'])
        ->assertOk()
        ->assertJsonCount(1, 'matches')
        ->assertJsonPath('matches.0.id', $this->existing->id)
        ->assertJsonPath('matches.0.matched_on', 'phone');
});

test('duplicate check matches by email when phone differs', function () {
    $this->actingAs($this->org->admin)
        ->postJson('/leads/duplicate-check', ['phone' => '9000000000', 'email' => 'AMIT@example.com'])
        ->assertJsonPath('matches.0.matched_on', 'email');
});

test('duplicate check never reveals leads the user cannot see', function () {
    $response = $this->actingAs($this->org->priya)->postJson('/leads/duplicate-check', ['phone' => '9824011111'])->assertOk();

    expect($response->json('matches'))->toBe([]);
    expect($response->getContent())->not->toContain($this->existing->lead_number);

    $manager = $this->actingAs($this->org->manager)->postJson('/leads/duplicate-check', ['phone' => '9824011111'])->assertOk();
    expect($manager->json('matches'))->toBe([]);
});

test('manual create with visible duplicate requires confirmation', function () {
    $this->actingAs($this->org->rahul)
        ->post('/leads', leadPayload(['phone' => '098240 11111']))
        ->assertSessionHasErrors('duplicate');

    expect(Lead::count())->toBe(1);

    $this->actingAs($this->org->rahul)
        ->post('/leads', leadPayload(['phone' => '098240 11111', 'confirm_duplicate' => true]))
        ->assertRedirect();

    $new = Lead::latest('id')->first();
    expect($new->is_duplicate)->toBeTrue()->and($new->duplicate_of_id)->toBe($this->existing->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_DUPLICATE_DETECTED', 'entity_id' => $new->id]);
});

test('hidden duplicate is flagged silently without blocking or leaking', function () {
    $this->actingAs($this->org->priya)->post('/leads', leadPayload(['phone' => '9824011111']))->assertRedirect();

    $new = Lead::latest('id')->first();
    expect($new->is_duplicate)->toBeTrue();

    $props = $this->actingAs($this->org->priya)->get("/leads/{$new->id}")->assertOk()->inertiaProps('lead');
    expect($props['is_duplicate'])->toBeTrue()->and($props['duplicate_of'])->toBeNull();
});

test('allow mode skips duplicate checks entirely', function () {
    app(SettingService::class)->updateGroup('lead', ['lead.duplicate_handling' => 'allow']);

    $this->actingAs($this->org->manager)->post('/leads', leadPayload(['phone' => '9824011111']))->assertRedirect();

    expect(Lead::latest('id')->first()->is_duplicate)->toBeFalse();
});

test('merge mode attaches inbound duplicates as enquiries on the existing lead', function () {
    app(SettingService::class)->updateGroup('lead', ['lead.duplicate_handling' => 'merge']);

    $result = app(LeadService::class)->createFromInbound([
        'first_name' => 'Amit', 'phone' => '+919824011111', 'source_id' => $this->existing->source_id,
    ], AssignmentType::Facebook, ['form' => 'fb']);

    expect($result['merged'])->toBeTrue()->and($result['lead']->id)->toBe($this->existing->id);
    expect(Lead::count())->toBe(1);
    expect($this->existing->enquiries()->where('is_duplicate', true)->count())->toBe(1);
});

test('flag mode creates a new flagged lead for inbound duplicates', function () {
    app(SettingService::class)->updateGroup('lead', ['lead.duplicate_handling' => 'flag']);

    $result = app(LeadService::class)->createFromInbound([
        'first_name' => 'Amit', 'email' => 'amit@example.com', 'source_id' => $this->existing->source_id,
    ], AssignmentType::Facebook);

    expect($result['merged'])->toBeFalse()->and($result['lead']->is_duplicate)->toBeTrue();
});
