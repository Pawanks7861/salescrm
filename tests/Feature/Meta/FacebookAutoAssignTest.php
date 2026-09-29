<?php

use App\Models\Lead;
use App\Models\LeadAssignmentRule;
use App\Services\SettingService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->org = salesOrg();
    $this->meta = metaSetup();
});

test('a facebook assignee in settings receives the new lead', function () {
    app(SettingService::class)->put('facebook.auto_assign_user_id', $this->org->rahul->id);
    metaFakeGraph(['9101' => metaLead('9101')]);

    metaPost($this, metaPayload(metaChange('9101')))->assertOk();

    expect(Lead::where('facebook_lead_id', '9101')->value('assigned_to'))->toBe($this->org->rahul->id);
});

test('the facebook assignee setting overrides assignment rules', function () {
    app(SettingService::class)->put('facebook.auto_assign_user_id', $this->org->rahul->id);
    LeadAssignmentRule::create([
        'name' => 'Everyone to Priya',
        'priority' => 1,
        'is_active' => true,
        'condition_type' => 'any',
        'assignment_type' => 'user',
        'assigned_user_id' => $this->org->priya->id,
    ]);
    metaFakeGraph(['9102' => metaLead('9102')]);

    metaPost($this, metaPayload(metaChange('9102')))->assertOk();

    expect(Lead::where('facebook_lead_id', '9102')->value('assigned_to'))->toBe($this->org->rahul->id);
});

test('facebook leads still follow assignment rules when no person is selected', function () {
    LeadAssignmentRule::create([
        'name' => 'Everyone to Priya',
        'priority' => 1,
        'is_active' => true,
        'condition_type' => 'any',
        'assignment_type' => 'user',
        'assigned_user_id' => $this->org->priya->id,
    ]);
    metaFakeGraph(['9103' => metaLead('9103')]);

    metaPost($this, metaPayload(metaChange('9103')))->assertOk();

    expect(Lead::where('facebook_lead_id', '9103')->value('assigned_to'))->toBe($this->org->priya->id);
});
