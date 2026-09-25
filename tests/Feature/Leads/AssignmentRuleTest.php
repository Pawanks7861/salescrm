<?php

use App\Enums\AssignmentType;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadAssignmentRule;
use App\Models\LeadSource;
use App\Services\Leads\LeadService;

beforeEach(function () {
    $this->org = salesOrg();
    $this->facebook = LeadSource::where('slug', 'facebook')->first();
});

function inbound(array $data = []): Lead
{
    return app(LeadService::class)->createFromInbound(array_merge([
        'first_name' => 'Inbound', 'phone' => '97'.random_int(10000000, 99999999),
        'source_id' => LeadSource::where('slug', 'facebook')->value('id'),
    ], $data), AssignmentType::Facebook)['lead'];
}

function rule(array $attrs): LeadAssignmentRule
{
    $rule = new LeadAssignmentRule(array_merge(['name' => 'Rule', 'priority' => 100, 'is_active' => true, 'condition_type' => 'any'], $attrs));
    $rule->save();

    return $rule;
}

test('sales users and managers cannot manage rules', function () {
    $this->actingAs($this->org->rahul)->get('/admin/assignment-rules')->assertForbidden();
    $this->actingAs($this->org->manager)->get('/admin/assignment-rules')->assertForbidden();
    $this->actingAs($this->org->admin)->get('/admin/assignment-rules')->assertOk();
});

test('admin creates, updates, disables and reorders rules with audit', function () {
    $this->actingAs($this->org->admin)->post('/admin/assignment-rules', [
        'name' => 'FB to Rahul', 'condition_type' => 'source', 'condition_value' => (string) $this->facebook->id,
        'assignment_type' => 'user', 'assigned_user_id' => $this->org->rahul->id, 'priority' => 10, 'is_active' => true,
    ])->assertRedirect();
    $rule = LeadAssignmentRule::sole();

    $this->actingAs($this->org->admin)->put("/admin/assignment-rules/{$rule->id}", [
        'name' => 'FB to Priya', 'condition_type' => 'source', 'condition_value' => (string) $this->facebook->id,
        'assignment_type' => 'user', 'assigned_user_id' => $this->org->priya->id, 'priority' => 10, 'is_active' => true,
    ])->assertRedirect();
    $this->actingAs($this->org->admin)->post("/admin/assignment-rules/{$rule->id}/toggle")->assertRedirect();

    expect($rule->fresh()->is_active)->toBeFalse()->and($rule->fresh()->assigned_user_id)->toBe($this->org->priya->id);
    foreach (['ASSIGNMENT_RULE_CREATED', 'ASSIGNMENT_RULE_UPDATED', 'ASSIGNMENT_RULE_DISABLED'] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action, 'entity_id' => $rule->id]);
    }

    $second = rule(['name' => 'Second', 'priority' => 20, 'assignment_type' => 'user', 'assigned_user_id' => $this->org->rahul->id]);
    $this->actingAs($this->org->admin)->post('/admin/assignment-rules/reorder', ['ids' => [$second->id, $rule->id]])->assertRedirect();
    expect($second->fresh()->priority)->toBeLessThan($rule->fresh()->priority);
});

test('rule validation requires targets that match the assignment type', function () {
    $this->actingAs($this->org->admin)->post('/admin/assignment-rules', [
        'name' => 'Bad', 'condition_type' => 'source', 'condition_value' => '', 'assignment_type' => 'round_robin', 'priority' => 10,
    ])->assertSessionHasErrors(['condition_value', 'user_pool']);
});

test('source rule assigns inbound leads to a specific user', function () {
    rule(['condition_type' => 'source', 'condition_value' => (string) $this->facebook->id, 'assignment_type' => 'user', 'assigned_user_id' => $this->org->priya->id]);

    $lead = inbound();

    expect($lead->assigned_to)->toBe($this->org->priya->id)->and($lead->team_id)->toBeNull();
    expect($lead->assignments()->sole()->assignment_type->value)->toBe('rule');
});

test('first matching rule by priority wins and disabled rules are skipped', function () {
    rule(['priority' => 1, 'is_active' => false, 'assignment_type' => 'user', 'assigned_user_id' => $this->org->outsider->id]);
    rule(['priority' => 5, 'condition_type' => 'city', 'condition_value' => 'surat', 'assignment_type' => 'user', 'assigned_user_id' => $this->org->priya->id]);
    rule(['priority' => 10, 'assignment_type' => 'user', 'assigned_user_id' => $this->org->rahul->id]);

    expect(inbound(['city' => 'Surat'])->assigned_to)->toBe($this->org->priya->id);
    expect(inbound(['city' => 'Pune'])->assigned_to)->toBe($this->org->rahul->id);
});

test('campaign rule with round robin rotates across the pool', function () {
    $campaign = Campaign::create(['name' => 'Diwali', 'platform' => 'facebook', 'external_id' => 'c1', 'is_active' => true]);
    rule(['condition_type' => 'campaign', 'condition_value' => (string) $campaign->id, 'assignment_type' => 'round_robin', 'user_pool_json' => [$this->org->rahul->id, $this->org->priya->id]]);

    $owners = collect(range(1, 4))->map(fn () => inbound(['campaign_id' => $campaign->id])->assigned_to)->all();

    expect($owners)->toBe([$this->org->rahul->id, $this->org->priya->id, $this->org->rahul->id, $this->org->priya->id]);
    expect(Lead::latest('id')->first()->assignments()->sole()->assignment_type->value)->toBe('round_robin');
});

test('deprecated team round robin rules never run, even when still active', function () {
    rule(['priority' => 1, 'assignment_type' => 'team_round_robin', 'assigned_team_id' => $this->org->team->id]);

    $lead = inbound();

    expect($lead->assigned_to)->toBeNull()->and($lead->team_id)->toBeNull()
        ->and($lead->assignments()->count())->toBe(0);
});

test('deprecated team rules are skipped and the next user rule applies', function () {
    rule(['priority' => 1, 'assignment_type' => 'team', 'assigned_team_id' => $this->org->team->id]);
    rule(['priority' => 2, 'condition_type' => 'team', 'condition_value' => (string) $this->org->team->id, 'assignment_type' => 'user', 'assigned_user_id' => $this->org->outsider->id]);
    rule(['priority' => 10, 'assignment_type' => 'user', 'assigned_user_id' => $this->org->priya->id]);

    $lead = inbound();

    expect($lead->assigned_to)->toBe($this->org->priya->id)->and($lead->team_id)->toBeNull();
    $this->actingAs($this->org->manager)->get("/leads/{$lead->id}")->assertForbidden();
    $this->actingAs($this->org->rahul)->get("/leads/{$lead->id}")->assertForbidden();
});

test('a deprecated team rule cannot be re-enabled or created', function () {
    $legacy = rule(['is_active' => false, 'assignment_type' => 'team', 'assigned_team_id' => $this->org->team->id]);

    $this->actingAs($this->org->admin)->post("/admin/assignment-rules/{$legacy->id}/toggle");
    expect($legacy->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->org->admin)->post('/admin/assignment-rules', [
        'name' => 'Team rule', 'condition_type' => 'any', 'assignment_type' => 'team', 'assigned_team_id' => $this->org->team->id, 'priority' => 10, 'is_active' => true,
    ])->assertSessionHasErrors('assignment_type');
    expect(LeadAssignmentRule::count())->toBe(1);
});

test('inbound leads stay unassigned when no rule matches', function () {
    expect(inbound()->assigned_to)->toBeNull();
});

test('manual creation by a sales executive ignores rules', function () {
    rule(['assignment_type' => 'user', 'assigned_user_id' => $this->org->priya->id]);

    $this->actingAs($this->org->rahul)->post('/leads', leadPayload())->assertRedirect();

    expect(Lead::sole()->assigned_to)->toBe($this->org->rahul->id);
});
