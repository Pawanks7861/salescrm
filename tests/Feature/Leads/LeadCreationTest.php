<?php

use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadEnquiry;
use App\Models\LeadSource;
use App\Models\User;

beforeEach(function () {
    $this->org = salesOrg();
});

test('sales executive creates a lead and becomes its owner', function () {
    $response = $this->actingAs($this->org->rahul)->post('/leads', leadPayload([
        'first_name' => 'Amit', 'last_name' => 'Desai', 'phone' => '98240 11111', 'email' => 'Amit@Example.com',
    ]));

    $lead = Lead::firstOrFail();
    $response->assertRedirect(route('leads.show', $lead));

    expect($lead->full_name)->toBe('Amit Desai')
        ->and($lead->email)->toBe('amit@example.com')
        ->and($lead->normalized_phone)->toBe('919824011111')
        ->and($lead->assigned_to)->toBe($this->org->rahul->id)
        ->and($lead->team_id)->toBeNull()
        ->and($lead->created_by)->toBe($this->org->rahul->id)
        ->and($lead->status->slug)->toBe('new')
        ->and($lead->lead_number)->toMatch('/^LD-\d{4}-000001$/');

    expect(LeadEnquiry::where('lead_id', $lead->id)->count())->toBe(1);
    expect(LeadAssignment::where('lead_id', $lead->id)->value('assignment_type')->value)->toBe('automatic');
});

test('protected columns cannot be mass assigned through the form', function () {
    $this->actingAs($this->org->rahul)->post('/leads', leadPayload([
        'lead_number' => 'HACK-1',
        'created_by' => $this->org->priya->id,
        'updated_by' => $this->org->priya->id,
        'assigned_to' => $this->org->priya->id,
        'team_id' => $this->org->otherTeam->id,
        'normalized_phone' => '000',
        'converted_at' => now()->toDateTimeString(),
        'lost_at' => now()->toDateTimeString(),
        'deleted_at' => now()->toDateTimeString(),
        'is_duplicate' => true,
    ]))->assertRedirect();

    $lead = Lead::withTrashed()->firstOrFail();

    expect($lead->lead_number)->not->toBe('HACK-1')
        ->and($lead->created_by)->toBe($this->org->rahul->id)
        ->and($lead->assigned_to)->toBe($this->org->rahul->id)
        ->and($lead->team_id)->toBeNull()
        ->and($lead->normalized_phone)->not->toBe('000')
        ->and($lead->converted_at)->toBeNull()
        ->and($lead->lost_at)->toBeNull()
        ->and($lead->trashed())->toBeFalse()
        ->and($lead->is_duplicate)->toBeFalse();
});

test('validation requires a name and a phone or email', function () {
    $this->actingAs($this->org->rahul)
        ->post('/leads', ['priority' => 'medium', 'source_id' => LeadSource::value('id')])
        ->assertSessionHasErrors(['first_name', 'phone']);

    expect(Lead::count())->toBe(0);
});

test('invalid priority and inactive source are rejected', function () {
    $source = LeadSource::first();
    $source->forceFill(['is_active' => false])->save();

    $this->actingAs($this->org->rahul)
        ->post('/leads', leadPayload(['priority' => 'critical', 'source_id' => $source->id]))
        ->assertSessionHasErrors(['priority', 'source_id']);
});

test('manager may assign a new lead to a team member', function () {
    $this->actingAs($this->org->manager)->post('/leads', leadPayload(['assigned_to' => $this->org->priya->id]))->assertRedirect();

    expect(Lead::first()->assigned_to)->toBe($this->org->priya->id);
});

test('manager without matching rule becomes owner of an automatically assigned lead', function () {
    $this->actingAs($this->org->manager)->post('/leads', leadPayload())->assertRedirect();

    expect(Lead::first()->assigned_to)->toBe($this->org->manager->id);
});

test('users without lead.create cannot open or submit the create form', function () {
    $user = User::factory()->create(); // no role

    $this->actingAs($user)->get('/leads/create')->assertForbidden();
    $this->actingAs($user)->post('/leads', leadPayload())->assertForbidden();
});
