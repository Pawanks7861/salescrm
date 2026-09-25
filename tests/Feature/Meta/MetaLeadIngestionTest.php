<?php

use App\Enums\FacebookEventStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\FacebookFieldMapping;
use App\Models\FacebookWebhookEvent;
use App\Models\Lead;
use App\Models\LeadCustomField;
use App\Models\LeadEnquiry;
use App\Models\LeadSource;
use App\Services\SettingService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->org = salesOrg();
    $this->meta = metaSetup();
});

// --- MetaLeadRetrievalTest -------------------------------------------------------

test('lead is fetched from Graph with the page token, requested fields and appsecret_proof', function () {
    metaFakeGraph(['7001' => metaLead('7001')]);

    metaPost($this, metaPayload(metaChange('7001')))->assertOk();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v25.0/7001')) {
            return false;
        }
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->hasHeader('Authorization', 'Bearer '.META_TEST_PAGE_TOKEN)
            && str_contains($query['fields'], 'field_data')
            && str_contains($query['fields'], 'campaign_name')
            && $query['appsecret_proof'] === hash_hmac('sha256', META_TEST_PAGE_TOKEN, META_TEST_SECRET)
            && ! isset($query['access_token']);
    });
});

test('Graph version comes from configuration', function () {
    config(['meta.graph_version' => 'v26.0']);
    Http::fake(['graph.facebook.com/v26.0/7002*' => Http::response(metaLead('7002'))]);

    metaPost($this, metaPayload(metaChange('7002')))->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com/v26.0/7002'));
    expect(Lead::where('facebook_lead_id', '7002')->exists())->toBeTrue();
});

test('a response for a different leadgen id is rejected as malformed', function () {
    metaFakeGraph(['7003' => metaLead('9999')]);

    metaPost($this, metaPayload(metaChange('7003')))->assertOk();

    $event = FacebookWebhookEvent::where('leadgen_id', '7003')->first();
    expect($event->processing_status)->toBe(FacebookEventStatus::Failed)
        ->and($event->error_category->value)->toBe('malformed')
        ->and(Lead::count())->toBe(0);
});

// --- MetaLeadIngestionTest -------------------------------------------------------

test('webhook lead becomes a CRM lead with mapped fields, source, campaign and Meta ids', function () {
    metaFakeGraph(['7010' => metaLead('7010')]);

    metaPost($this, metaPayload(metaChange('7010')))->assertOk();

    $lead = Lead::where('facebook_lead_id', '7010')->sole();
    expect($lead->first_name)->toBe('Amit')
        ->and($lead->last_name)->toBe('Desai')
        ->and($lead->full_name)->toBe('Amit Desai')
        ->and($lead->email)->toBe('amit.desai@example.com')
        ->and($lead->phone)->toBe('+919812345678')
        ->and($lead->normalized_phone)->toBe('919812345678')
        ->and($lead->city)->toBe('Pune')
        ->and($lead->source_id)->toBe(LeadSource::where('slug', 'facebook')->value('id'))
        ->and($lead->facebook_form_id)->toBe('222')
        ->and($lead->facebook_page_id)->toBe('111')
        ->and($lead->facebook_ad_id)->toBe('555')
        ->and($lead->facebook_adset_id)->toBe('556')
        ->and($lead->facebook_campaign_id)->toBe('120210000000777')
        ->and($lead->created_by)->toBeNull()
        ->and($lead->status->slug)->toBe('new');

    $campaign = Campaign::where('platform', 'facebook')->where('external_id', '120210000000777')->sole();
    expect($campaign->name)->toBe('Diwali Loans')->and($lead->campaign_id)->toBe($campaign->id);

    $event = FacebookWebhookEvent::where('leadgen_id', '7010')->sole();
    expect($event->processing_status)->toBe(FacebookEventStatus::Processed)
        ->and($event->lead_id)->toBe($lead->id)
        ->and($event->outcome)->toBe('created')
        ->and($event->attempt_count)->toBe(1);
});

test('instagram submissions use the Instagram source', function () {
    metaFakeGraph(['7011' => metaLead('7011', [], ['platform' => 'ig'])]);

    metaPost($this, metaPayload(metaChange('7011')));

    expect(Lead::where('facebook_lead_id', '7011')->value('source_id'))->toBe(LeadSource::where('slug', 'instagram')->value('id'));
});

test('a per-form lead source override wins', function () {
    $website = LeadSource::where('slug', '!=', 'facebook')->where('is_active', true)->first();
    $this->meta->form->forceFill(['lead_source_id' => $website->id])->save();
    metaFakeGraph(['7012' => metaLead('7012')]);

    metaPost($this, metaPayload(metaChange('7012')));

    expect(Lead::where('facebook_lead_id', '7012')->value('source_id'))->toBe($website->id);
});

test('campaign rows are upserted by external id and names refreshed', function () {
    metaFakeGraph([
        '7013' => metaLead('7013', ['full_name' => 'One Person', 'phone_number' => '+919800000001']),
        '7014' => metaLead('7014', ['full_name' => 'Two Person', 'phone_number' => '+919800000002'], ['campaign_name' => 'Diwali Loans (renamed)']),
    ]);

    metaPost($this, metaPayload(metaChange('7013')));
    metaPost($this, metaPayload(metaChange('7014')));

    expect(Campaign::where('external_id', '120210000000777')->count())->toBe(1)
        ->and(Campaign::where('external_id', '120210000000777')->value('name'))->toBe('Diwali Loans (renamed)');
});

test('phone numbers are normalised with the CRM default country, not a hard-coded +91', function () {
    app(SettingService::class)->updateGroup('lead', ['lead.default_country_code' => '44']);
    metaFakeGraph(['7015' => metaLead('7015', ['full_name' => 'UK Person', 'phone_number' => '07700 900123'])]);

    metaPost($this, metaPayload(metaChange('7015')));

    $lead = Lead::where('facebook_lead_id', '7015')->sole();
    expect($lead->normalized_phone)->toBe('447700900123');
});

test('a lead without a name gets the configured placeholder', function () {
    metaFakeGraph(['7016' => metaLead('7016', ['email' => 'noname@example.com'])]);

    metaPost($this, metaPayload(metaChange('7016')));

    expect(Lead::where('facebook_lead_id', '7016')->value('first_name'))->toBe('Facebook Lead');
});

test('invalid email or phone answers are kept on the enquiry but not copied to the lead', function () {
    metaFakeGraph(['7017' => metaLead('7017', ['full_name' => 'Bad Data', 'email' => 'not-an-email', 'phone_number' => 'call me'])]);

    metaPost($this, metaPayload(metaChange('7017')));

    $lead = Lead::where('facebook_lead_id', '7017')->sole();
    expect($lead->email)->toBeNull()->and($lead->phone)->toBeNull();
    expect($lead->enquiries()->sole()->enquiry_data_json)->toMatchArray(['email' => 'not-an-email', 'phone_number' => 'call me']);
});

test('the Meta payload can never set owner, team, creator, status or internal ids', function () {
    metaFakeGraph(['7018' => metaLead('7018', [
        'full_name' => 'Sneaky Person',
        'phone_number' => '+919811111111',
        'assigned_to' => (string) $this->org->rahul->id,
        'team_id' => (string) $this->org->team->id,
        'created_by' => (string) $this->org->admin->id,
        'status_id' => (string) leadStatusId('won'),
        'lead_number' => 'HACK-1',
        'id' => '1',
        'is_duplicate' => '1',
        'estimated_value' => '999999',
    ])]);

    metaPost($this, metaPayload(metaChange('7018')));

    $lead = Lead::where('facebook_lead_id', '7018')->sole();
    expect($lead->assigned_to)->toBeNull()
        ->and($lead->team_id)->toBeNull()
        ->and($lead->created_by)->toBeNull()
        ->and($lead->status->slug)->toBe('new')
        ->and($lead->lead_number)->not->toBe('HACK-1')
        ->and($lead->estimated_value)->toBeNull();
    // Kept as enquiry answers only.
    expect($lead->enquiries()->sole()->enquiry_data_json)->toHaveKey('assigned_to');
});

test('mapped custom fields are stored through the custom field service', function () {
    $field = LeadCustomField::query()->forceCreate(['name' => 'Budget', 'slug' => 'budget_range', 'field_type' => 'text', 'is_active' => true, 'sort_order' => 1]);
    FacebookFieldMapping::query()->forceCreate(['facebook_form_id' => $this->meta->form->id, 'meta_field' => 'budget', 'target_type' => 'custom', 'lead_custom_field_id' => $field->id]);
    metaFakeGraph(['7019' => metaLead('7019')]);

    metaPost($this, metaPayload(metaChange('7019')));

    $lead = Lead::where('facebook_lead_id', '7019')->sole();
    expect($lead->customFieldValues()->where('lead_custom_field_id', $field->id)->value('value'))->toBe('25-50 lakh');
});

test('answers are bounded: long values truncated, control characters stripped, field count capped', function () {
    config(['meta.max_answer_length' => 50, 'meta.max_fields' => 6]);
    $answers = ['full_name' => "Long\x07 Name", 'phone_number' => '+919822222222', 'notes' => str_repeat('a', 500)];
    foreach (range(1, 10) as $i) {
        $answers["extra_{$i}"] = "v{$i}";
    }
    metaFakeGraph(['7020' => metaLead('7020', $answers)]);

    metaPost($this, metaPayload(metaChange('7020')));

    $enquiry = LeadEnquiry::where('external_id', '7020')->sole();
    expect(count($enquiry->enquiry_data_json))->toBeLessThanOrEqual(6)
        ->and(mb_strlen($enquiry->enquiry_data_json['notes']))->toBeLessThanOrEqual(50)
        ->and($enquiry->enquiry_data_json['full_name'])->not->toContain("\x07")
        ->and($enquiry->metadata_json['truncated'])->toBeTrue();
});

test('lead creation writes a created activity and a FACEBOOK_LEAD_CREATED audit without answers', function () {
    metaFakeGraph(['7021' => metaLead('7021')]);

    metaPost($this, metaPayload(metaChange('7021')));

    $lead = Lead::where('facebook_lead_id', '7021')->sole();
    expect($lead->activities()->where('description', 'like', '%Home Loan Enquiry%')->exists())->toBeTrue();

    $audit = AuditLog::where('action', 'FACEBOOK_LEAD_CREATED')->sole();
    expect($audit->entity_id)->toBe($lead->id)
        ->and($audit->new_values_json['leadgen_id'])->toBe('7021');
    expect(json_encode($audit->new_values_json))->not->toContain('25-50 lakh')->not->toContain('amit.desai@example.com');
});

// --- MetaDuplicateLeadTest / MetaLeadEnquiryTest ---------------------------------

test('merge mode: a repeat submission becomes a new enquiry on the existing lead', function () {
    app(SettingService::class)->updateGroup('lead', ['lead.duplicate_handling' => 'merge']);
    $existing = Lead::factory()->assignedTo($this->org->rahul)->create([
        'first_name' => 'Amit', 'last_name' => null, 'phone' => '9812345678', 'normalized_phone' => '919812345678',
        'email' => null, 'city' => null, 'estimated_value' => 500000, 'priority' => 'high',
    ]);
    $statusBefore = $existing->status_id;
    metaFakeGraph(['7030' => metaLead('7030')]);

    metaPost($this, metaPayload(metaChange('7030')));

    expect(Lead::count())->toBe(1);
    $existing->refresh();
    expect($existing->assigned_to)->toBe($this->org->rahul->id)
        ->and($existing->status_id)->toBe($statusBefore)
        ->and((float) $existing->estimated_value)->toBe(500000.0)
        ->and($existing->priority->value)->toBe('high')
        // Missing contact fields are filled…
        ->and($existing->email)->toBe('amit.desai@example.com')
        ->and($existing->last_name)->toBe('Desai')
        ->and($existing->city)->toBe('Pune')
        // …but existing ones are never overwritten.
        ->and($existing->first_name)->toBe('Amit');

    $enquiry = $existing->enquiries()->sole();
    expect($enquiry->channel)->toBe('facebook')
        ->and($enquiry->external_id)->toBe('7030')
        ->and($enquiry->is_duplicate)->toBeTrue();

    $event = FacebookWebhookEvent::where('leadgen_id', '7030')->sole();
    expect($event->outcome)->toBe('merged')->and($event->lead_id)->toBe($existing->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'FACEBOOK_ENQUIRY_CREATED', 'entity_id' => $existing->id]);
});

test('merge never overwrites an existing email or phone', function () {
    app(SettingService::class)->updateGroup('lead', ['lead.duplicate_handling' => 'merge']);
    $existing = Lead::factory()->create(['phone' => '9812345678', 'normalized_phone' => '919812345678', 'email' => 'original@example.com', 'city' => 'Mumbai']);
    metaFakeGraph(['7031' => metaLead('7031')]);

    metaPost($this, metaPayload(metaChange('7031')));

    $existing->refresh();
    expect($existing->email)->toBe('original@example.com')->and($existing->city)->toBe('Mumbai');
});

test('flag mode: a repeat submission creates a new lead flagged as duplicate', function () {
    app(SettingService::class)->updateGroup('lead', ['lead.duplicate_handling' => 'flag']);
    $existing = Lead::factory()->create(['phone' => '9812345678', 'normalized_phone' => '919812345678']);
    metaFakeGraph(['7032' => metaLead('7032')]);

    metaPost($this, metaPayload(metaChange('7032')));

    $new = Lead::where('facebook_lead_id', '7032')->sole();
    expect($new->is_duplicate)->toBeTrue()->and($new->duplicate_of_id)->toBe($existing->id);
    expect(FacebookWebhookEvent::where('leadgen_id', '7032')->value('outcome'))->toBe('flagged');
});

test('allow mode: a repeat submission creates an independent lead', function () {
    app(SettingService::class)->updateGroup('lead', ['lead.duplicate_handling' => 'allow']);
    Lead::factory()->create(['phone' => '9812345678', 'normalized_phone' => '919812345678']);
    metaFakeGraph(['7033' => metaLead('7033')]);

    metaPost($this, metaPayload(metaChange('7033')));

    expect(Lead::count())->toBe(2)->and(Lead::where('facebook_lead_id', '7033')->value('is_duplicate'))->toBeFalse();
});

test('RELEASE C: every submission is preserved as an enquiry with page, form, campaign and readable labels', function () {
    app(SettingService::class)->updateGroup('lead', ['lead.duplicate_handling' => 'merge']);
    metaFakeGraph([
        '7034' => metaLead('7034'),
        '7035' => metaLead('7035', ['full_name' => 'Amit Desai', 'phone_number' => '+919812345678', 'budget' => '50-75 lakh']),
        '7036' => metaLead('7036', ['full_name' => 'Amit Desai', 'phone_number' => '+919812345678', 'budget' => '75+ lakh']),
    ]);

    foreach (['7034', '7035', '7036'] as $id) {
        metaPost($this, metaPayload(metaChange($id)))->assertOk();
    }

    $lead = Lead::sole();
    $enquiries = $lead->enquiries()->orderBy('id')->get();
    expect($enquiries)->toHaveCount(3)
        ->and($enquiries->pluck('external_id')->all())->toBe(['7034', '7035', '7036'])
        ->and($enquiries->pluck('enquiry_data_json.budget')->all())->toBe(['25-50 lakh', '50-75 lakh', '75+ lakh']);
    expect($enquiries[0]->metadata_json)->toMatchArray(['page_name' => 'Acme Homes', 'form_name' => 'Home Loan Enquiry', 'campaign_name' => 'Diwali Loans'])
        ->and($enquiries[0]->metadata_json['labels']['budget'])->toBe('What is your budget?');

    $props = $this->actingAs($this->org->admin)->get("/leads/{$lead->id}")->assertOk()->inertiaProps();
    expect($props['enquiries'])->toHaveCount(3)
        ->and($props['enquiries'][0]['meta']['form_name'])->toBe('Home Loan Enquiry')
        ->and($props['enquiries'][0]['labels']['budget'])->toBe('What is your budget?')
        ->and($props['facebook']['page_name'])->toBe('Acme Homes')
        ->and($props['facebook']['form_name'])->toBe('Home Loan Enquiry')
        ->and($props['facebook']['facebook_enquiries'])->toBe(3);
});

test('lead list filters by Facebook page and form server-side', function () {
    metaFakeGraph(['7040' => metaLead('7040')]);
    metaPost($this, metaPayload(metaChange('7040')));
    Lead::factory()->create(['facebook_form_id' => '333', 'facebook_page_id' => '444']);

    $byForm = $this->actingAs($this->org->admin)->get('/leads?facebook_form=222')->inertiaProps('leads');
    $byPage = $this->actingAs($this->org->admin)->get('/leads?facebook_page=444')->inertiaProps('leads');

    expect($byForm['total'])->toBe(1)->and($byForm['data'][0]['lead_number'])->toBe(Lead::where('facebook_lead_id', '7040')->value('lead_number'));
    expect($byPage['total'])->toBe(1);
});
