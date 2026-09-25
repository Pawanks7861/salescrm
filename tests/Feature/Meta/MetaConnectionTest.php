<?php

use App\Enums\AuditAction;
use App\Enums\FacebookIntegrationStatus;
use App\Enums\MetaErrorCategory;
use App\Logging\RedactSecrets;
use App\Models\AuditLog;
use App\Models\FacebookFieldMapping;
use App\Models\FacebookForm;
use App\Models\FacebookIntegration;
use App\Models\FacebookPage;
use App\Models\Lead;
use App\Models\LeadCustomField;
use App\Models\LeadSource;
use App\Services\AuditService;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\MetaFieldMappingService;
use App\Services\Meta\MetaIntegrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->org = salesOrg();
    metaConfigure();
});

function metaOAuthFakes(?array $scopes = null): void
{
    Http::fake([
        'graph.facebook.com/v25.0/oauth/access_token*' => Http::sequence()
            ->push(['access_token' => 'EAASHORTLIVED000000000000', 'token_type' => 'bearer'])
            ->push(['access_token' => META_TEST_USER_TOKEN, 'token_type' => 'bearer', 'expires_in' => 5184000]),
        'graph.facebook.com/v25.0/debug_token*' => Http::response(['data' => [
            'is_valid' => true, 'app_id' => '123456789012345', 'user_id' => '999',
            'scopes' => $scopes ?? MetaIntegrationService::REQUIRED_SCOPES,
        ]]),
        'graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => [
            ['id' => '111', 'name' => 'Acme Homes', 'category' => 'Real Estate', 'access_token' => META_TEST_PAGE_TOKEN, 'tasks' => ['ADVERTISE', 'MANAGE']],
        ]]),
        'graph.facebook.com/v25.0/me*' => Http::response(['id' => '999', 'name' => 'Meta Admin']),
    ]);
}

function metaStartOAuth($test, $user): string
{
    $response = $test->actingAs($user)->post(route('admin.integrations.facebook.connect'), [], ['X-Inertia' => 'true']);
    $location = $response->headers->get('X-Inertia-Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);

    return $query['state'];
}

// --- MetaOAuthTest ---------------------------------------------------------------

test('connect redirects to the Meta OAuth dialog with version, scopes, redirect uri and a random state', function () {
    $response = $this->actingAs($this->org->super)->post(route('admin.integrations.facebook.connect'), [], ['X-Inertia' => 'true']);

    $response->assertStatus(409);
    $location = $response->headers->get('X-Inertia-Location');
    expect($location)->toStartWith('https://www.facebook.com/v25.0/dialog/oauth?');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);
    expect($query['client_id'])->toBe('123456789012345')
        ->and($query['redirect_uri'])->toBe(route('admin.integrations.facebook.callback'))
        ->and($query['scope'])->toContain('leads_retrieval')->toContain('pages_manage_metadata')
        ->and(strlen($query['state']))->toBeGreaterThanOrEqual(40)
        ->and($location)->not->toContain(META_TEST_SECRET);
});

test('a valid callback exchanges the code, stores an encrypted token and syncs pages', function () {
    metaOAuthFakes();
    $state = metaStartOAuth($this, $this->org->super);

    $this->actingAs($this->org->super)
        ->get(route('admin.integrations.facebook.callback', ['code' => 'AQD-auth-code-123', 'state' => $state]))
        ->assertRedirect(route('admin.integrations.facebook.index'))
        ->assertSessionHas('success');

    $integration = FacebookIntegration::sole();
    expect($integration->status)->toBe(FacebookIntegrationStatus::Connected)
        ->and($integration->access_token_encrypted)->toBe(META_TEST_USER_TOKEN)
        ->and($integration->facebook_user_name)->toBe('Meta Admin')
        ->and($integration->token_expires_at)->not->toBeNull();

    $raw = DB::table('facebook_integrations')->value('access_token_encrypted');
    expect($raw)->not->toContain(META_TEST_USER_TOKEN)->not->toBe(META_TEST_USER_TOKEN);
    expect(DB::table('facebook_pages')->value('page_access_token_encrypted'))->not->toContain(META_TEST_PAGE_TOKEN);
    expect(FacebookPage::sole()->page_name)->toBe('Acme Homes');

    $this->assertDatabaseHas('audit_logs', ['action' => 'FACEBOOK_CONNECTED']);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth/access_token') && str_contains($r->url(), 'code=AQD-auth-code-123'));
});

test('callback with a missing, wrong or reused state is rejected without calling Meta', function () {
    Http::fake();
    $state = metaStartOAuth($this, $this->org->super);

    $this->actingAs($this->org->super)->get(route('admin.integrations.facebook.callback', ['code' => 'x']))->assertSessionHas('error');
    $this->actingAs($this->org->super)->get(route('admin.integrations.facebook.callback', ['code' => 'x', 'state' => 'forged-state']))->assertSessionHas('error');

    // The real state was consumed by the forged attempt (single use), so it no longer works either.
    $this->actingAs($this->org->super)->get(route('admin.integrations.facebook.callback', ['code' => 'x', 'state' => $state]))->assertSessionHas('error');

    Http::assertNothingSent();
    expect(FacebookIntegration::count())->toBe(0);
});

test('state expires after ten minutes', function () {
    Http::fake();
    $state = metaStartOAuth($this, $this->org->super);
    $this->travel(11)->minutes();

    $this->actingAs($this->org->super)->get(route('admin.integrations.facebook.callback', ['code' => 'x', 'state' => $state]))->assertSessionHas('error');
    Http::assertNothingSent();
});

test('state from one admin session cannot be used by another session', function () {
    Http::fake();
    $state = metaStartOAuth($this, $this->org->super);

    $this->flushSession();
    $this->actingAs($this->org->super)->get(route('admin.integrations.facebook.callback', ['code' => 'x', 'state' => $state]))->assertSessionHas('error');
    Http::assertNothingSent();
});

test('a denied authorization is handled without storing anything', function () {
    Http::fake();
    $state = metaStartOAuth($this, $this->org->super);

    $this->actingAs($this->org->super)->get(route('admin.integrations.facebook.callback', ['error' => 'access_denied', 'state' => $state]))->assertSessionHas('error');
    expect(FacebookIntegration::count())->toBe(0);
});

test('missing permissions are detected and shown as Permission Missing', function () {
    metaOAuthFakes(['pages_show_list', 'pages_read_engagement']);
    $state = metaStartOAuth($this, $this->org->super);

    $this->actingAs($this->org->super)->get(route('admin.integrations.facebook.callback', ['code' => 'c', 'state' => $state]));

    $integration = FacebookIntegration::sole();
    expect($integration->status)->toBe(FacebookIntegrationStatus::PermissionMissing)
        ->and($integration->missing_scopes_json)->toContain('leads_retrieval');
});

test('a token issued for another Meta app is rejected', function () {
    Http::fake([
        'graph.facebook.com/v25.0/oauth/access_token*' => Http::response(['access_token' => 'EAAOTHERAPP0000000000']),
        'graph.facebook.com/v25.0/debug_token*' => Http::response(['data' => ['is_valid' => true, 'app_id' => '555', 'scopes' => []]]),
    ]);
    $state = metaStartOAuth($this, $this->org->super);

    $this->actingAs($this->org->super)->get(route('admin.integrations.facebook.callback', ['code' => 'c', 'state' => $state]))->assertSessionHas('error');
    expect(FacebookIntegration::count())->toBe(0);
});

test('the manual token form is disabled unless enabled by env and restricted to Super Admin', function () {
    $this->actingAs($this->org->super)->post(route('admin.integrations.facebook.manual-token'), ['access_token' => META_TEST_USER_TOKEN])->assertForbidden();

    config(['meta.allow_manual_token' => true]);
    $this->actingAs($this->org->admin)->post(route('admin.integrations.facebook.manual-token'), ['access_token' => META_TEST_USER_TOKEN])->assertForbidden();

    Http::fake([
        'graph.facebook.com/v25.0/debug_token*' => Http::response(['data' => ['is_valid' => true, 'app_id' => '123456789012345', 'scopes' => MetaIntegrationService::REQUIRED_SCOPES]]),
        'graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => []]),
        'graph.facebook.com/v25.0/me*' => Http::response(['id' => '42', 'name' => 'System User']),
    ]);
    $this->actingAs($this->org->super)->post(route('admin.integrations.facebook.manual-token'), ['access_token' => META_TEST_USER_TOKEN])->assertSessionHas('success');

    expect(FacebookIntegration::sole()->token_type)->toBe('system_user');
    $props = $this->actingAs($this->org->super)->get(route('admin.integrations.facebook.index'))->getContent();
    expect($props)->not->toContain(META_TEST_USER_TOKEN);
});

// --- MetaConnectionSecurityTest ----------------------------------------------------

test('only facebook.manage users reach the integration screens (Super Admin by default)', function () {
    foreach (['rahul', 'priya', 'manager', 'admin'] as $who) {
        $this->actingAs($this->org->{$who})->get(route('admin.integrations.facebook.index'))->assertForbidden();
        $this->actingAs($this->org->{$who})->get(route('admin.integrations.facebook.events.index'))->assertForbidden();
        $this->actingAs($this->org->{$who})->post(route('admin.integrations.facebook.connect'))->assertForbidden();
    }

    $this->actingAs($this->org->super)->get(route('admin.integrations.facebook.index'))->assertOk();
    $this->actingAs($this->org->super)->get(route('admin.integrations.facebook.events.index'))->assertOk();
});

test('guests are redirected to login and the callback requires an authenticated admin', function () {
    $this->get(route('admin.integrations.facebook.index'))->assertRedirect(route('login'));
    $this->get(route('admin.integrations.facebook.callback', ['code' => 'x', 'state' => 'y']))->assertRedirect(route('login'));
});

test('RELEASE E: no token, secret or verify token reaches Vue props', function () {
    $meta = metaSetup();
    $lead = Lead::factory()->create(['facebook_lead_id' => '123', 'facebook_form_id' => '222', 'facebook_page_id' => '111']);

    $pages = [
        route('admin.integrations.facebook.index'),
        route('admin.integrations.facebook.events.index'),
        route('admin.integrations.facebook.forms.mapping', $meta->form),
        route('dashboard'),
        route('leads.show', $lead),
        route('leads.index'),
    ];

    foreach ($pages as $url) {
        $html = $this->actingAs($this->org->super)->get($url)->assertOk()->getContent();
        foreach ([META_TEST_USER_TOKEN, META_TEST_PAGE_TOKEN, META_TEST_SECRET, META_TEST_VERIFY, 'access_token_encrypted', 'page_access_token_encrypted'] as $secret) {
            expect($html)->not->toContain($secret);
        }
    }
});

test('RELEASE E: tokens are redacted from logs and audit values', function () {
    $logFile = storage_path('logs/meta-redaction-test.log');
    @unlink($logFile);
    config(['logging.channels.meta_test' => ['driver' => 'single', 'path' => $logFile, 'tap' => [RedactSecrets::class]]]);

    Log::channel('meta_test')->warning('Graph failed https://graph.facebook.com/v25.0/me?access_token='.META_TEST_USER_TOKEN.'&appsecret_proof=abc', [
        'token' => META_TEST_PAGE_TOKEN, 'app_secret' => META_TEST_SECRET, 'authorization' => 'Bearer '.META_TEST_USER_TOKEN,
        'code' => 'AQD-oauth-code', 'leadgen_id' => '4242',
        'exception' => new RuntimeException('call failed with Bearer '.META_TEST_USER_TOKEN),
    ]);

    $log = file_get_contents($logFile);
    expect($log)->not->toContain(META_TEST_USER_TOKEN)->not->toContain(META_TEST_PAGE_TOKEN)->not->toContain(META_TEST_SECRET)->not->toContain('AQD-oauth-code')
        ->and($log)->toContain('4242')->toContain('[REDACTED]');
    @unlink($logFile);

    $audit = app(AuditService::class)->log(AuditAction::FacebookConnected, 'integrations', null, 'x', ['access_token' => META_TEST_USER_TOKEN], [
        'page_access_token' => META_TEST_PAGE_TOKEN, 'app_secret' => META_TEST_SECRET, 'verify_token' => META_TEST_VERIFY, 'code' => 'AQD-oauth-code', 'leadgen_id' => '4242',
    ]);
    $stored = json_encode(AuditLog::find($audit->id)->toArray());
    expect($stored)->not->toContain(META_TEST_USER_TOKEN)->not->toContain(META_TEST_PAGE_TOKEN)->not->toContain(META_TEST_SECRET)
        ->not->toContain(META_TEST_VERIFY)->not->toContain('AQD-oauth-code')->toContain('4242');
});

test('RELEASE E: exception pages for Meta errors never include tokens', function () {
    $e = new MetaApiException(MetaErrorCategory::Authentication, 'Invalid token '.META_TEST_USER_TOKEN.' for https://x.test/?access_token='.META_TEST_PAGE_TOKEN);

    expect($e->getMessage())->not->toContain(META_TEST_USER_TOKEN)->not->toContain(META_TEST_PAGE_TOKEN);
});

test('models hide encrypted tokens from serialization', function () {
    $meta = metaSetup();

    expect(json_encode($meta->integration->fresh()->toArray()))->not->toContain('access_token');
    expect(json_encode($meta->page->fresh()->toArray()))->not->toContain('access_token');
});

test('disconnect wipes tokens, unsubscribes pages and preserves leads and history', function () {
    $meta = metaSetup();
    $lead = Lead::factory()->create(['facebook_lead_id' => '321']);
    Http::fake(['graph.facebook.com/v25.0/111/subscribed_apps*' => Http::response(['success' => true])]);

    $this->actingAs($this->org->super)->post(route('admin.integrations.facebook.disconnect'))->assertSessionHas('success');

    $integration = $meta->integration->fresh();
    expect($integration->status)->toBe(FacebookIntegrationStatus::Disconnected)
        ->and($integration->access_token_encrypted)->toBeNull()
        ->and($meta->page->fresh()->page_access_token_encrypted)->toBeNull()
        ->and($meta->page->fresh()->is_selected)->toBeFalse();
    expect(Lead::find($lead->id))->not->toBeNull()->and(FacebookForm::count())->toBe(1);
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '111/subscribed_apps'));
    $this->assertDatabaseHas('audit_logs', ['action' => 'FACEBOOK_DISCONNECTED']);
});

test('test connection updates health from debug_token and subscription checks', function () {
    $meta = metaSetup();
    Http::fake([
        'graph.facebook.com/v25.0/debug_token*' => Http::response(['data' => ['is_valid' => true, 'app_id' => '123456789012345', 'scopes' => MetaIntegrationService::REQUIRED_SCOPES]]),
        'graph.facebook.com/v25.0/111/subscribed_apps*' => Http::response(['data' => [['id' => '123456789012345', 'subscribed_fields' => ['leadgen']]]]),
    ]);

    $this->actingAs($this->org->super)->post(route('admin.integrations.facebook.test'))->assertSessionHas('success');

    expect($meta->integration->fresh()->last_verified_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'FACEBOOK_CONNECTION_CHECKED']);
});

test('an expired token found by test connection shows Needs Reauthorization', function () {
    $meta = metaSetup();
    Http::fake(['graph.facebook.com/v25.0/debug_token*' => Http::response(['data' => ['is_valid' => false, 'app_id' => '123456789012345', 'error' => ['code' => 190]]])]);

    $this->actingAs($this->org->super)->post(route('admin.integrations.facebook.test'))->assertSessionHas('error');

    expect($meta->integration->fresh()->status)->toBe(FacebookIntegrationStatus::NeedsReauthorization);
});

test('the integration page makes no Meta calls on load', function () {
    metaSetup();
    Http::fake();

    $this->actingAs($this->org->super)->get(route('admin.integrations.facebook.index'))->assertOk();
    $this->actingAs($this->org->super)->get(route('dashboard'))->assertOk();

    Http::assertNothingSent();
});

// --- MetaPageSyncTest / MetaFormSyncTest -------------------------------------------

test('enabling a page subscribes the app to leadgen with the page token', function () {
    $meta = metaSetup([], ['is_selected' => false, 'is_subscribed' => false]);
    Http::fake(['graph.facebook.com/v25.0/111/subscribed_apps*' => Http::response(['success' => true])]);

    $this->actingAs($this->org->super)->put(route('admin.integrations.facebook.pages.update', $meta->page), ['is_selected' => true])->assertSessionHas('success');

    expect($meta->page->fresh()->is_selected)->toBeTrue()->and($meta->page->fresh()->is_subscribed)->toBeTrue();
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '111/subscribed_apps') && $r['subscribed_fields'] === 'leadgen' && $r->hasHeader('Authorization', 'Bearer '.META_TEST_PAGE_TOKEN));
    $this->assertDatabaseHas('audit_logs', ['action' => 'FACEBOOK_PAGE_SUBSCRIBED']);
});

test('a failed subscription is reported and left visible for the admin to retry', function () {
    $meta = metaSetup([], ['is_selected' => false, 'is_subscribed' => false]);
    Http::fake(['graph.facebook.com/v25.0/111/subscribed_apps*' => Http::response(['error' => ['code' => 200, 'message' => 'Requires pages_manage_metadata']], 403)]);

    $this->actingAs($this->org->super)->put(route('admin.integrations.facebook.pages.update', $meta->page), ['is_selected' => true])->assertSessionHas('error');

    $page = $meta->page->fresh();
    expect($page->is_subscribed)->toBeFalse()->and($page->subscription_error)->toContain('permission');
    expect($this->actingAs($this->org->super)->get(route('admin.integrations.facebook.index'))->inertiaProps('health.pages_unsubscribed'))->toBe(1);
});

test('refreshing pages upserts by page id and marks pages no longer returned inactive', function () {
    $meta = metaSetup();
    Http::fake(['graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => [
        ['id' => '333', 'name' => 'New Page', 'access_token' => 'EAANEWPAGETOKEN000000'],
    ]])]);

    $this->actingAs($this->org->super)->post(route('admin.integrations.facebook.pages.refresh'))->assertSessionHas('success');

    expect(FacebookPage::where('page_id', '333')->value('page_name'))->toBe('New Page')
        ->and($meta->page->fresh()->is_active)->toBeFalse();
});

test('loading forms upserts forms with questions and keeps existing enable flags', function () {
    $meta = metaSetup(['is_enabled' => false]);
    Http::fake(['graph.facebook.com/v25.0/111/leadgen_forms*' => Http::response(['data' => [
        ['id' => '222', 'name' => 'Home Loan Enquiry v2', 'status' => 'ACTIVE', 'questions' => [['key' => 'full_name', 'label' => 'Full name', 'type' => 'FULL_NAME']]],
        ['id' => '223', 'name' => 'Car Loan', 'status' => 'ARCHIVED', 'questions' => []],
    ]])]);

    $this->actingAs($this->org->super)->post(route('admin.integrations.facebook.forms.refresh', $meta->page))->assertSessionHas('success');

    expect($meta->form->fresh()->form_name)->toBe('Home Loan Enquiry v2')
        ->and($meta->form->fresh()->is_enabled)->toBeFalse()
        ->and(FacebookForm::where('form_id', '223')->value('status'))->toBe('ARCHIVED');
});

test('forms can be enabled, disabled and given a lead source with audit', function () {
    $meta = metaSetup();
    $source = LeadSource::where('slug', 'instagram')->first();

    $this->actingAs($this->org->super)->put(route('admin.integrations.facebook.forms.update', $meta->form), ['is_enabled' => false, 'lead_source_id' => $source->id])->assertSessionHas('success');

    expect($meta->form->fresh()->is_enabled)->toBeFalse()->and($meta->form->fresh()->lead_source_id)->toBe($source->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'FACEBOOK_FORM_UPDATED', 'entity_id' => $meta->form->id]);
});

// --- MetaFieldMappingTest -----------------------------------------------------------

test('mapping screen lists questions with standard defaults', function () {
    $meta = metaSetup();

    $props = $this->actingAs($this->org->super)->get(route('admin.integrations.facebook.forms.mapping', $meta->form))->assertOk()->inertiaProps();

    $rows = collect($props['rows'])->keyBy('meta_field');
    expect($rows['email']['target'])->toBe('lead:email')
        ->and($rows['phone_number']['target'])->toBe('lead:phone')
        ->and($rows['budget']['target'])->toBe('none');
});

test('saving a mapping accepts allow-listed targets and custom fields and is audited', function () {
    $meta = metaSetup();
    $field = LeadCustomField::query()->forceCreate(['name' => 'Budget', 'slug' => 'budget', 'field_type' => 'text', 'is_active' => true]);

    $this->actingAs($this->org->super)->put(route('admin.integrations.facebook.forms.mapping.save', $meta->form), ['targets' => [
        'budget' => "custom:{$field->id}", 'city' => 'lead:state', 'email' => 'lead:email',
    ]])->assertSessionHas('success');

    expect(FacebookFieldMapping::where('meta_field', 'budget')->value('lead_custom_field_id'))->toBe($field->id)
        ->and(FacebookFieldMapping::where('meta_field', 'city')->value('lead_field'))->toBe('state');
    $this->assertDatabaseHas('audit_logs', ['action' => 'FACEBOOK_FIELD_MAPPING_UPDATED', 'entity_id' => $meta->form->id]);
});

test('mapping into protected or unknown fields is rejected', function (string $target) {
    $meta = metaSetup();

    $this->actingAs($this->org->super)->put(route('admin.integrations.facebook.forms.mapping.save', $meta->form), ['targets' => ['full_name' => $target]])
        ->assertSessionHasErrors('targets.full_name');

    expect(FacebookFieldMapping::count())->toBe(0);
})->with(['lead:assigned_to', 'lead:team_id', 'lead:created_by', 'lead:status_id', 'lead:id', 'lead:lead_number', 'lead:deleted_at', 'lead:converted_at', 'lead:lost_at', 'lead:estimated_value', 'custom:999999', 'sql:drop']);

test('a mapping to a deactivated custom field falls back to enquiry-only', function () {
    $meta = metaSetup();
    $field = LeadCustomField::query()->forceCreate(['name' => 'Budget', 'slug' => 'budget', 'field_type' => 'text', 'is_active' => true]);
    FacebookFieldMapping::query()->forceCreate(['facebook_form_id' => $meta->form->id, 'meta_field' => 'budget', 'target_type' => 'custom', 'lead_custom_field_id' => $field->id]);
    $field->forceFill(['is_active' => false])->save();

    $result = app(MetaFieldMappingService::class)->apply($meta->form, [['name' => 'budget', 'values' => ['10 lakh']]]);

    expect($result['custom'])->toBe([])->and($result['unmapped'])->toContain('budget')->and($result['answers']['budget'])->toBe('10 lakh');
});
