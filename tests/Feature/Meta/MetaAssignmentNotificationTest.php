<?php

use App\Enums\AssignmentType;
use App\Enums\FacebookEventStatus;
use App\Jobs\ProcessMetaLeadEvent;
use App\Models\AuditLog;
use App\Models\FacebookWebhookEvent;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadAssignmentRule;
use App\Models\LeadEnquiry;
use App\Models\LeadSource;
use App\Services\Leads\LeadAssignmentService;
use App\Services\Meta\MetaLeadIngestionService;
use App\Services\SettingService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->org = salesOrg();
    $this->meta = metaSetup();
});

function metaRule(array $attrs): LeadAssignmentRule
{
    $rule = new LeadAssignmentRule(array_merge(['name' => 'Meta rule', 'priority' => 10, 'is_active' => true, 'condition_type' => 'any'], $attrs));
    $rule->save();

    return $rule;
}

function metaLeadFor(string $id, string $name, string $phone): array
{
    return metaLead($id, ['full_name' => $name, 'phone_number' => $phone, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com']);
}

// --- MetaAssignmentTest ------------------------------------------------------------

test('a facebook form rule assigns leads from that form', function () {
    metaRule(['condition_type' => 'facebook_form', 'condition_value' => '222', 'assignment_type' => 'user', 'assigned_user_id' => $this->org->priya->id]);
    metaFakeGraph(['6001' => metaLead('6001')]);

    metaPost($this, metaPayload(metaChange('6001')));

    $lead = Lead::where('facebook_lead_id', '6001')->sole();
    expect($lead->assigned_to)->toBe($this->org->priya->id)->and($lead->team_id)->toBeNull();
    expect($lead->assignments()->sole()->assignment_type->value)->toBe('rule');
});

test('a rule for another form does not match; the generic source rule still applies', function () {
    metaRule(['condition_type' => 'facebook_form', 'condition_value' => '999', 'assignment_type' => 'user', 'assigned_user_id' => $this->org->priya->id, 'priority' => 5]);
    metaRule(['condition_type' => 'source', 'condition_value' => (string) LeadSource::where('slug', 'facebook')->value('id'), 'assignment_type' => 'user', 'assigned_user_id' => $this->org->rahul->id, 'priority' => 10]);
    metaFakeGraph(['6002' => metaLead('6002')]);

    metaPost($this, metaPayload(metaChange('6002')));

    expect(Lead::where('facebook_lead_id', '6002')->value('assigned_to'))->toBe($this->org->rahul->id);
});

test('with no matching rule the lead stays unassigned', function () {
    metaFakeGraph(['6003' => metaLead('6003')]);

    metaPost($this, metaPayload(metaChange('6003')));

    expect(Lead::where('facebook_lead_id', '6003')->value('assigned_to'))->toBeNull();
});

test('admins can create a facebook form rule only for synced forms', function () {
    $base = ['name' => 'Form rule', 'condition_type' => 'facebook_form', 'assignment_type' => 'user', 'assigned_user_id' => $this->org->rahul->id, 'priority' => 10, 'is_active' => true];

    $this->actingAs($this->org->admin)->post('/admin/assignment-rules', [...$base, 'condition_value' => '424242'])->assertSessionHasErrors('condition_value');
    $this->actingAs($this->org->admin)->post('/admin/assignment-rules', [...$base, 'condition_value' => '222'])->assertSessionHasNoErrors();

    expect(LeadAssignmentRule::where('condition_type', 'facebook_form')->value('condition_value'))->toBe('222');
});

// --- MetaRoundRobinIdempotencyTest (RELEASE D) ----------------------------------------

test('RELEASE D: round robin advances once per new lead and never on retries or redeliveries', function () {
    $rule = metaRule(['condition_type' => 'facebook_form', 'condition_value' => '222', 'assignment_type' => 'round_robin', 'user_pool_json' => [$this->org->rahul->id, $this->org->priya->id]]);
    metaFakeGraph([
        '6010' => metaLeadFor('6010', 'Lead One', '+919800006010'),
        '6011' => metaLeadFor('6011', 'Lead Two', '+919800006011'),
    ]);

    metaPost($this, metaPayload(metaChange('6010')));
    $firstOwner = Lead::where('facebook_lead_id', '6010')->value('assigned_to');
    $pointer = $rule->fresh()->last_assigned_user_id;

    // Redelivery + manual reprocessing of the same lead.
    metaPost($this, metaPayload(metaChange('6010')));
    app(MetaLeadIngestionService::class)->process(FacebookWebhookEvent::where('leadgen_id', '6010')->sole());
    (new ProcessMetaLeadEvent(FacebookWebhookEvent::where('leadgen_id', '6010')->value('id')))->handle(app(MetaLeadIngestionService::class));

    expect($rule->fresh()->last_assigned_user_id)->toBe($pointer)
        ->and(LeadAssignment::count())->toBe(1);

    metaPost($this, metaPayload(metaChange('6011')));
    $secondOwner = Lead::where('facebook_lead_id', '6011')->value('assigned_to');

    expect($secondOwner)->not->toBe($firstOwner)
        ->and(LeadAssignment::count())->toBe(2)
        ->and([$firstOwner, $secondOwner])->toEqualCanonicalizing([$this->org->rahul->id, $this->org->priya->id]);
});

test('a merged repeat enquiry does not reassign or advance round robin', function () {
    app(SettingService::class)->updateGroup('lead', ['lead.duplicate_handling' => 'merge']);
    $rule = metaRule(['condition_type' => 'facebook_form', 'condition_value' => '222', 'assignment_type' => 'round_robin', 'user_pool_json' => [$this->org->rahul->id, $this->org->priya->id]]);
    metaFakeGraph(['6020' => metaLead('6020'), '6021' => metaLead('6021')]);

    metaPost($this, metaPayload(metaChange('6020')));
    $owner = Lead::sole()->assigned_to;
    $pointer = $rule->fresh()->last_assigned_user_id;

    metaPost($this, metaPayload(metaChange('6021')));

    expect(Lead::count())->toBe(1)->and(Lead::sole()->assigned_to)->toBe($owner)
        ->and($rule->fresh()->last_assigned_user_id)->toBe($pointer)
        ->and(LeadAssignment::count())->toBe(1)
        ->and(LeadEnquiry::count())->toBe(2);
});

// --- MetaNotificationTest ----------------------------------------------------------

test('the assignee gets one "New Facebook lead assigned" notification linking to Lead 360', function () {
    metaRule(['condition_type' => 'facebook_form', 'condition_value' => '222', 'assignment_type' => 'user', 'assigned_user_id' => $this->org->rahul->id]);
    metaFakeGraph(['6030' => metaLead('6030')]);

    metaPost($this, metaPayload(metaChange('6030')));
    metaPost($this, metaPayload(metaChange('6030')));

    $lead = Lead::sole();
    $notifications = $this->org->rahul->notifications()->get();
    expect($notifications)->toHaveCount(1);
    $data = $notifications->first()->data;
    expect($data['message'])->toBe("New Facebook lead assigned: Amit Desai (ID {$lead->id})")
        ->and($data['lead_id'])->toBe($lead->id)
        ->and($data['lead_number'])->toBe($lead->lead_number)
        ->and($data['source'])->toBe('Facebook')
        ->and($data['campaign'])->toBe('Diwali Loans')
        ->and($data['url'])->toBe("/leads/{$lead->id}");
    expect(json_encode($data))->not->toContain('amit.desai@example.com')->not->toContain('25-50 lakh');
    expect($this->org->priya->notifications()->count())->toBe(0);
});

test('unassigned leads notify no one', function () {
    metaFakeGraph(['6031' => metaLead('6031')]);

    metaPost($this, metaPayload(metaChange('6031')));

    expect(DatabaseNotification::count())->toBe(0);
});

test('the owner is notified once about a repeat enquiry when enabled', function () {
    app(SettingService::class)->updateGroup('lead', ['lead.duplicate_handling' => 'merge']);
    Lead::factory()->assignedTo($this->org->rahul)->create(['phone' => '9812345678', 'normalized_phone' => '919812345678']);
    metaFakeGraph(['6032' => metaLead('6032')]);

    metaPost($this, metaPayload(metaChange('6032')));
    metaPost($this, metaPayload(metaChange('6032')));

    $notes = $this->org->rahul->notifications()->get();
    expect($notes)->toHaveCount(1)->and($notes->first()->data['event'])->toBe('facebook_enquiry');
});

test('opening a notification re-checks lead visibility', function () {
    metaRule(['condition_type' => 'facebook_form', 'condition_value' => '222', 'assignment_type' => 'user', 'assigned_user_id' => $this->org->rahul->id]);
    metaFakeGraph(['6033' => metaLead('6033')]);
    metaPost($this, metaPayload(metaChange('6033')));
    $lead = Lead::sole();
    $notification = $this->org->rahul->notifications()->sole();

    // Reassigned to Priya afterwards: Rahul's old notification must not open the lead.
    app(LeadAssignmentService::class)->assign($lead, $this->org->priya, AssignmentType::Manual, $this->org->admin);

    $this->actingAs($this->org->rahul)->get("/leads/{$lead->id}")->assertForbidden();
    $this->actingAs($this->org->rahul)->get(route('notifications.open', $notification->id))
        ->assertRedirect(route('notifications.index'));
});

// --- MetaVisibilityTest (RELEASE F) ----------------------------------------------------

test('RELEASE F: Facebook leads follow normal visibility — Rahul sees his, Priya does not', function () {
    metaRule(['condition_type' => 'facebook_form', 'condition_value' => '222', 'assignment_type' => 'user', 'assigned_user_id' => $this->org->rahul->id]);
    metaFakeGraph(['6040' => metaLead('6040')]);
    metaPost($this, metaPayload(metaChange('6040')));
    $lead = Lead::sole();

    $this->actingAs($this->org->rahul)->get("/leads/{$lead->id}")->assertOk();
    $this->actingAs($this->org->priya)->get("/leads/{$lead->id}")->assertForbidden();
    $this->actingAs($this->org->manager)->get("/leads/{$lead->id}")->assertForbidden();
    $this->actingAs($this->org->admin)->get("/leads/{$lead->id}")->assertOk();
    $this->actingAs($this->org->outsider)->get("/leads/{$lead->id}")->assertForbidden();

    expect($this->actingAs($this->org->priya)->get('/leads?facebook_form=222')->inertiaProps('leads.total'))->toBe(0);
    expect($this->actingAs($this->org->rahul)->get('/leads?facebook_form=222')->inertiaProps('leads.total'))->toBe(1);
});

test('sales users do not see the webhook event link on Lead 360', function () {
    metaRule(['condition_type' => 'facebook_form', 'condition_value' => '222', 'assignment_type' => 'user', 'assigned_user_id' => $this->org->rahul->id]);
    metaFakeGraph(['6041' => metaLead('6041')]);
    metaPost($this, metaPayload(metaChange('6041')));
    $lead = Lead::sole();

    expect($this->actingAs($this->org->rahul)->get("/leads/{$lead->id}")->inertiaProps('facebook.event'))->toBeNull();
    expect($this->actingAs($this->org->super)->get("/leads/{$lead->id}")->inertiaProps('facebook.event.status'))->toBe('Processed');
});

test('the dashboard widget is shown only to integration managers', function () {
    expect($this->actingAs($this->org->rahul)->get('/dashboard')->inertiaProps('facebook'))->toBeNull();
    expect($this->actingAs($this->org->admin)->get('/dashboard')->inertiaProps('facebook'))->toBeNull();
    expect($this->actingAs($this->org->super)->get('/dashboard')->inertiaProps('facebook.connected'))->toBeTrue();
});

// --- MetaXssTest -------------------------------------------------------------------

test('script payloads in answers are stored as text and never rendered as HTML', function () {
    $xss = '<script>alert(1)</script><img src=x onerror=alert(2)>';
    metaFakeGraph(['6050' => metaLead('6050', ['full_name' => 'Evil <b>Name</b>', 'phone_number' => '+919800006050', 'budget' => $xss])]);

    metaPost($this, metaPayload(metaChange('6050')));

    $lead = Lead::sole();
    expect(LeadEnquiry::sole()->enquiry_data_json['budget'])->toBe($xss);

    $html = $this->actingAs($this->org->super)->get("/leads/{$lead->id}")->assertOk()->getContent();
    expect($html)->not->toContain('<script>alert(1)</script>')->not->toContain('<img src=x onerror=alert(2)>');

    // The panel renders with text interpolation only.
    $panel = file_get_contents(resource_path('js/Components/leads/EnquiriesPanel.vue'));
    expect($panel)->not->toContain('v-html');
    foreach (glob(resource_path('js/Pages/Admin/Integrations/Facebook/*.vue')) as $file) {
        expect(file_get_contents($file))->not->toContain('v-html');
    }
});

// --- MetaAuditTest -----------------------------------------------------------------

test('integration actions are audited and routine deliveries stay out of the audit log', function () {
    metaFakeGraph(['6060' => metaLead('6060')]);

    metaPost($this, metaPayload(metaChange('6060')));
    metaPost($this, metaPayload(metaChange('6060')));

    expect(AuditLog::where('action', 'FACEBOOK_LEAD_CREATED')->count())->toBe(1)
        ->and(AuditLog::where('action', 'FACEBOOK_WEBHOOK_RECEIVED')->count())->toBe(0);

    $this->actingAs($this->org->super)->put(route('admin.integrations.facebook.settings'), ['settings' => [
        'placeholder_name' => 'Meta Lead', 'use_instagram_source' => true, 'auto_enable_new_forms' => false, 'notify_on_repeat_enquiry' => true, 'event_retention_days' => 90,
    ]])->assertSessionHas('success');
    expect(app(SettingService::class)->get('facebook.placeholder_name'))->toBe('Meta Lead');
    $this->assertDatabaseHas('audit_logs', ['action' => 'SETTING_CHANGED']);
});

test('the events screen shows safe details only and filters server-side', function () {
    metaFakeGraph(['6070' => metaLead('6070'), '6071' => Http::response(['error' => ['code' => 200]], 403)]);
    metaPost($this, metaPayload(metaChange('6070'), metaChange('6071')));

    $props = $this->actingAs($this->org->super)->get(route('admin.integrations.facebook.events.index', ['status' => 'failed']))->assertOk()->inertiaProps();

    expect($props['events']['total'])->toBe(1)->and($props['events']['data'][0]['leadgen_id'])->toBe('6071');
    $all = json_encode($this->actingAs($this->org->super)->get(route('admin.integrations.facebook.events.index'))->inertiaProps());
    expect($all)->not->toContain('amit.desai@example.com')->not->toContain('25-50 lakh')->not->toContain('+919812345678');
});

test('stored event payloads contain Meta ids only, never answers', function () {
    metaFakeGraph(['6080' => metaLead('6080')]);

    metaPost($this, metaPayload(metaChange('6080')));

    $event = FacebookWebhookEvent::sole();
    expect(array_keys($event->payload_json))->each->toBeIn(['leadgen_id', 'page_id', 'form_id', 'ad_id', 'adgroup_id', 'created_time']);
    expect(json_encode($event->toArray()))->not->toContain('Amit')->not->toContain('amit.desai');
});

test('meta:prune-events deletes only old completed events and never leads', function () {
    metaFakeGraph(['6090' => metaLead('6090')]);
    metaPost($this, metaPayload(metaChange('6090')));
    FacebookWebhookEvent::query()->update(['received_at' => now()->subDays(400)]);
    FacebookWebhookEvent::query()->forceCreate(['leadgen_id' => '6091', 'origin' => 'webhook', 'page_id' => '111', 'received_at' => now()->subDays(400), 'processing_status' => FacebookEventStatus::Failed]);

    $this->artisan('meta:prune-events')->assertSuccessful();

    expect(FacebookWebhookEvent::pluck('leadgen_id')->all())->toBe(['6091'])->and(Lead::count())->toBe(1);

    // Idempotency survives pruning via the unique enquiry external id.
    metaPost($this, metaPayload(metaChange('6090')));
    expect(Lead::count())->toBe(1)->and(LeadEnquiry::count())->toBe(1);
});

// --- No export -----------------------------------------------------------------------

test('no export or download endpoints exist for the integration', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'integrations/facebook') || str_contains($r->uri(), 'webhooks'))
        ->map(fn ($r) => $r->uri());

    expect($routes->filter(fn ($uri) => preg_match('/export|download|csv|xlsx/i', $uri))->all())->toBe([]);
});
