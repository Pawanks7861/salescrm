<?php

use App\Enums\FacebookEventStatus;
use App\Enums\FacebookIntegrationStatus;
use App\Jobs\ProcessMetaLeadEvent;
use App\Jobs\SyncMetaFormLeads;
use App\Models\FacebookForm;
use App\Models\FacebookWebhookEvent;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadEnquiry;
use App\Services\Meta\MetaLeadIngestionService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->org = salesOrg();
    $this->meta = metaSetup();
});

// --- MetaWebhookIdempotencyTest --------------------------------------------------

test('RELEASE B: the same leadgen_id delivered twice creates one lead, one enquiry and counts the redelivery', function () {
    metaFakeGraph(['8001' => metaLead('8001')]);

    metaPost($this, metaPayload(metaChange('8001')))->assertOk();
    metaPost($this, metaPayload(metaChange('8001')))->assertOk();

    expect(Lead::count())->toBe(1)
        ->and(LeadEnquiry::count())->toBe(1)
        ->and(FacebookWebhookEvent::count())->toBe(1)
        ->and(FacebookWebhookEvent::sole()->delivery_count)->toBe(2);
    Http::assertSentCount(1);
});

test('the webhook only queues work on the integrations queue', function () {
    Queue::fake();

    metaPost($this, metaPayload(metaChange('8002')))->assertOk();

    Queue::assertPushedOn('integrations', ProcessMetaLeadEvent::class);
    expect(FacebookWebhookEvent::sole()->processing_status)->toBe(FacebookEventStatus::Queued)
        ->and(Lead::count())->toBe(0);
    Http::assertNothingSent();
});

test('an event already processed is never processed again, even if its job runs twice', function () {
    metaFakeGraph(['8003' => metaLead('8003')]);
    metaPost($this, metaPayload(metaChange('8003')));
    $event = FacebookWebhookEvent::sole();

    (new ProcessMetaLeadEvent($event->id))->handle(app(MetaLeadIngestionService::class));
    app(MetaLeadIngestionService::class)->process($event->fresh());

    expect(Lead::count())->toBe(1)->and(LeadEnquiry::count())->toBe(1)->and($event->fresh()->attempt_count)->toBe(1);
});

test('a lead already present (e.g. from another origin) marks the event duplicate without creating anything', function () {
    Lead::factory()->create(['facebook_lead_id' => '8004']);
    metaFakeGraph(['8004' => metaLead('8004')]);

    metaPost($this, metaPayload(metaChange('8004')));

    expect(Lead::count())->toBe(1)
        ->and(FacebookWebhookEvent::sole()->processing_status)->toBe(FacebookEventStatus::Duplicate);
});

// --- MetaWebhookBatchTest --------------------------------------------------------

test('each change in a batch is handled independently', function () {
    metaFakeGraph([
        '8010' => metaLead('8010', ['full_name' => 'Batch One', 'phone_number' => '+919800000010']),
        '8011' => metaLead('8011', ['full_name' => 'Batch Two', 'phone_number' => '+919800000011']),
    ]);

    $payload = metaPayload(
        metaChange('8010'),
        ['field' => 'feed', 'value' => ['post_id' => '1']],        // not leadgen → skipped
        ['field' => 'leadgen', 'value' => ['leadgen_id' => 'x!']], // malformed → skipped
        metaChange('8011'),
        metaChange('8012', '999999'),                             // unknown page → ignored
    );

    metaPost($this, $payload)->assertOk();

    expect(Lead::pluck('facebook_lead_id')->sort()->values()->all())->toBe(['8010', '8011']);
    expect(FacebookWebhookEvent::where('leadgen_id', '8012')->value('processing_status'))->toBe(FacebookEventStatus::Ignored);
    expect(FacebookWebhookEvent::count())->toBe(3);
});

test('a failure on one lead in a batch does not affect the others', function () {
    metaFakeGraph([
        '8020' => Http::response(['error' => ['code' => 100, 'message' => 'Unsupported get request', 'type' => 'GraphMethodException']], 400),
        '8021' => metaLead('8021', ['full_name' => 'Still Works', 'phone_number' => '+919800000021']),
    ]);

    metaPost($this, metaPayload(metaChange('8020'), metaChange('8021')))->assertOk();

    expect(FacebookWebhookEvent::where('leadgen_id', '8020')->value('processing_status'))->toBe(FacebookEventStatus::Failed);
    expect(Lead::where('facebook_lead_id', '8021')->exists())->toBeTrue();
});

// --- MetaRetryTest ---------------------------------------------------------------

test('temporary Graph errors are retried with backoff, then recover without duplicates', function () {
    $calls = 0;
    metaFakeGraph(['8030' => function () use (&$calls) {
        $calls++;

        return $calls === 1
            ? Http::response(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable', 'is_transient' => true]], 503)
            : Http::response(metaLead('8030'));
    }]);

    metaPost($this, metaPayload(metaChange('8030')))->assertOk();

    $event = FacebookWebhookEvent::sole();
    expect($event->processing_status)->toBe(FacebookEventStatus::Queued)
        ->and($event->error_category->value)->toBe('temporary')
        ->and($event->next_attempt_at)->not->toBeNull()
        ->and($event->attempt_count)->toBe(1)
        ->and(Lead::count())->toBe(0);

    // Worker picks up the released job later.
    (new ProcessMetaLeadEvent($event->id))->handle(app(MetaLeadIngestionService::class));

    expect($event->fresh()->processing_status)->toBe(FacebookEventStatus::Processed)
        ->and($event->fresh()->attempt_count)->toBe(2)
        ->and(Lead::count())->toBe(1)
        ->and(LeadEnquiry::count())->toBe(1);
});

test('rate limits and network failures are retryable', function (Closure $response, string $category) {
    metaFakeGraph(['8031' => $response]);

    metaPost($this, metaPayload(metaChange('8031')));

    $event = FacebookWebhookEvent::sole();
    expect($event->processing_status)->toBe(FacebookEventStatus::Queued)->and($event->error_category->value)->toBe($category);
})->with([
    'rate limit' => [fn () => fn () => Http::response(['error' => ['code' => 32, 'message' => 'Page request limit reached']], 400), 'rate_limit'],
    'http 429' => [fn () => fn () => Http::response('', 429), 'rate_limit'],
]);

test('real connection failures become sanitized, retryable network errors', function () {
    // Http::failedConnection() crashes this PHP build, so use a closed local port instead.
    Http::preventStrayRequests(false);
    config(['meta.graph_url' => 'http://127.0.0.1:9', 'meta.http.connect_timeout' => 1, 'meta.http.timeout' => 1]);

    metaPost($this, metaPayload(metaChange('8031')));

    $event = FacebookWebhookEvent::sole();
    expect($event->processing_status)->toBe(FacebookEventStatus::Queued)
        ->and($event->error_category->value)->toBe('network')
        ->and($event->error_message)->not->toContain(META_TEST_PAGE_TOKEN)
        ->and(Lead::count())->toBe(0);
});

test('retryable errors dead-letter after the configured attempts', function () {
    config(['meta.max_attempts' => 3]);
    metaFakeGraph(['8032' => Http::response(['error' => ['code' => 2, 'is_transient' => true]], 503)]);

    metaPost($this, metaPayload(metaChange('8032')));
    $event = FacebookWebhookEvent::sole();
    foreach (range(1, 4) as $i) {
        (new ProcessMetaLeadEvent($event->id))->handle(app(MetaLeadIngestionService::class));
    }

    $event->refresh();
    expect($event->processing_status)->toBe(FacebookEventStatus::Failed)
        ->and($event->attempt_count)->toBe(3)
        ->and($event->failed_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'FACEBOOK_WEBHOOK_FAILED', 'entity_id' => $event->id]);
});

test('admin can retry a failed event and it recovers without duplication', function () {
    $calls = 0;
    metaFakeGraph(['8033' => function () use (&$calls) {
        return ++$calls === 1
            ? Http::response(['error' => ['code' => 100, 'error_subcode' => 33, 'message' => 'Object does not exist']], 400)
            : Http::response(metaLead('8033'));
    }]);
    metaPost($this, metaPayload(metaChange('8033')));
    $event = FacebookWebhookEvent::sole();
    expect($event->processing_status)->toBe(FacebookEventStatus::Failed);

    $this->actingAs($this->org->super)->post(route('admin.integrations.facebook.events.retry', $event))->assertRedirect()->assertSessionHas('success');

    expect($event->fresh()->processing_status)->toBe(FacebookEventStatus::Processed)->and(Lead::count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'FACEBOOK_WEBHOOK_RETRIED', 'entity_id' => $event->id]);

    // A second retry of a processed event is refused.
    $this->actingAs($this->org->super)->post(route('admin.integrations.facebook.events.retry', $event))->assertSessionHas('error');
    expect(Lead::count())->toBe(1);
});

test('auth failures cannot be retried until Meta is reconnected', function () {
    metaFakeGraph(['8034' => Http::response(['error' => ['code' => 190, 'message' => 'Error validating access token']], 400)]);
    metaPost($this, metaPayload(metaChange('8034')));
    $event = FacebookWebhookEvent::sole();
    $this->meta->integration->forceFill(['status' => FacebookIntegrationStatus::NeedsReauthorization])->save();

    $this->actingAs($this->org->super)->post(route('admin.integrations.facebook.events.retry', $event))->assertSessionHas('error');

    expect($event->fresh()->processing_status)->toBe(FacebookEventStatus::Failed);
});

test('meta:retry-failed re-dispatches stuck events and retries transient failures', function () {
    metaFakeGraph(['8035' => metaLead('8035'), '8036' => metaLead('8036', ['full_name' => 'Other', 'phone_number' => '+919800000036'])]);

    $stuck = FacebookWebhookEvent::query()->forceCreate([
        'leadgen_id' => '8035', 'origin' => 'webhook', 'page_id' => '111', 'form_id' => '222', 'facebook_page_id' => $this->meta->page->id,
        'received_at' => now()->subHour(), 'processing_status' => FacebookEventStatus::Processing, 'processing_started_at' => now()->subHour(), 'attempt_count' => 1,
    ]);
    $failed = FacebookWebhookEvent::query()->forceCreate([
        'leadgen_id' => '8036', 'origin' => 'webhook', 'page_id' => '111', 'form_id' => '222', 'facebook_page_id' => $this->meta->page->id,
        'received_at' => now()->subHour(), 'processing_status' => FacebookEventStatus::Failed, 'error_category' => 'network', 'attempt_count' => 5, 'failed_at' => now(),
    ]);

    $this->artisan('meta:retry-failed --failed')->assertSuccessful();

    expect($stuck->fresh()->processing_status)->toBe(FacebookEventStatus::Processed)
        ->and($failed->fresh()->processing_status)->toBe(FacebookEventStatus::Processed)
        ->and(Lead::count())->toBe(2);
});

// --- MetaPermanentFailureTest ----------------------------------------------------

test('permanent errors fail immediately with no retry loop', function (array $error, int $status, string $category) {
    metaFakeGraph(['8040' => Http::response(['error' => $error], $status)]);

    metaPost($this, metaPayload(metaChange('8040')));

    $event = FacebookWebhookEvent::sole();
    expect($event->processing_status)->toBe(FacebookEventStatus::Failed)
        ->and($event->error_category->value)->toBe($category)
        ->and($event->attempt_count)->toBe(1)
        ->and($event->next_attempt_at)->toBeNull()
        ->and(Lead::count())->toBe(0);
})->with([
    'permission' => [['code' => 200, 'message' => 'Requires leads_retrieval permission'], 403, 'permission'],
    'not found' => [['code' => 100, 'error_subcode' => 33, 'message' => 'Object does not exist'], 400, 'not_found'],
    'page token revoked' => [['code' => 190, 'message' => 'Error validating access token'], 400, 'page_unavailable'],
]);

test('a disconnected integration fails events as configuration errors without calling Meta', function () {
    $this->meta->integration->forceFill(['status' => FacebookIntegrationStatus::Disconnected])->save();
    Http::fake();

    metaPost($this, metaPayload(metaChange('8041')));

    $event = FacebookWebhookEvent::sole();
    expect($event->processing_status)->toBe(FacebookEventStatus::Failed)->and($event->error_code)->toBe('integration_disconnected');
    Http::assertNothingSent();
});

test('failure messages stored on events never contain tokens', function () {
    metaFakeGraph(['8042' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token - Cannot parse access token '.META_TEST_PAGE_TOKEN]], 400)]);

    metaPost($this, metaPayload(metaChange('8042')));

    expect(json_encode(FacebookWebhookEvent::sole()->toArray()))->not->toContain(META_TEST_PAGE_TOKEN);
});

// --- MetaDisabledFormTest / MetaUnknownFormTest ------------------------------------

test('RELEASE G: a disabled form is ignored with HTTP 200 and no Graph call', function () {
    $this->meta->form->forceFill(['is_enabled' => false])->save();
    Http::fake();

    metaPost($this, metaPayload(metaChange('8050')))->assertOk();

    $event = FacebookWebhookEvent::sole();
    expect($event->processing_status)->toBe(FacebookEventStatus::Ignored)->and($event->error_code)->toBe('form_disabled')->and(Lead::count())->toBe(0);
    Http::assertNothingSent();
});

test('a disabled page and an unknown page are ignored with HTTP 200', function () {
    $this->meta->page->forceFill(['is_selected' => false])->save();
    Http::fake();

    metaPost($this, metaPayload(metaChange('8051'), metaChange('8052', '424242')))->assertOk();

    expect(FacebookWebhookEvent::where('leadgen_id', '8051')->value('error_code'))->toBe('page_disabled')
        ->and(FacebookWebhookEvent::where('leadgen_id', '8052')->value('error_code'))->toBe('unknown_page')
        ->and(Lead::count())->toBe(0);
});

test('an unknown form on a receiving page is discovered from Meta and ingested (auto-enable on)', function () {
    metaFakeGraph(
        ['8053' => metaLead('8053', [], ['form_id' => '777'])],
        ['graph.facebook.com/v25.0/777*' => Http::response(['id' => '777', 'name' => 'New Spring Form', 'status' => 'ACTIVE', 'questions' => [['key' => 'full_name', 'label' => 'Full name', 'type' => 'FULL_NAME']]])],
    );

    metaPost($this, metaPayload(metaChange('8053', '111', '777')))->assertOk();

    $form = FacebookForm::where('form_id', '777')->sole();
    expect($form->form_name)->toBe('New Spring Form')->and($form->is_enabled)->toBeTrue();
    expect(Lead::where('facebook_lead_id', '8053')->value('facebook_form_id'))->toBe('777');
    $this->assertDatabaseHas('audit_logs', ['action' => 'FACEBOOK_FORM_SYNCED', 'entity_id' => $form->id]);
});

test('an unknown form with auto-enable off is held for review and recovers after enabling', function () {
    app(SettingService::class)->updateGroup('facebook', ['facebook.auto_enable_new_forms' => false]);
    metaFakeGraph(
        ['8054' => metaLead('8054', [], ['form_id' => '778'])],
        ['graph.facebook.com/v25.0/778*' => Http::response(['id' => '778', 'name' => 'Needs Review', 'status' => 'ACTIVE'])],
    );

    metaPost($this, metaPayload(metaChange('8054', '111', '778')))->assertOk();

    $event = FacebookWebhookEvent::sole();
    expect($event->processing_status)->toBe(FacebookEventStatus::Failed)
        ->and($event->error_code)->toBe('form_pending_review')
        ->and(Lead::count())->toBe(0);

    FacebookForm::where('form_id', '778')->update(['is_enabled' => true]);
    $this->actingAs($this->org->super)->post(route('admin.integrations.facebook.events.retry', $event));

    expect($event->fresh()->processing_status)->toBe(FacebookEventStatus::Processed)->and(Lead::count())->toBe(1);
});

test('an unknown form whose metadata cannot be fetched fails visibly instead of being lost', function () {
    metaFakeGraph(
        ['8055' => metaLead('8055', [], ['form_id' => '779'])],
        ['graph.facebook.com/v25.0/779*' => Http::response(['error' => ['code' => 200, 'message' => 'Permissions error']], 403)],
    );

    metaPost($this, metaPayload(metaChange('8055', '111', '779')))->assertOk();

    expect(FacebookWebhookEvent::sole()->processing_status)->toBe(FacebookEventStatus::Failed)
        ->and(FacebookWebhookEvent::sole()->error_category->value)->toBe('permission');
});

// --- MetaBackfillTest --------------------------------------------------------------

test('sync recent leads is queued and ingests through the same pipeline, skipping known leads', function () {
    metaFakeGraph(['8060' => metaLead('8060')]);
    metaPost($this, metaPayload(metaChange('8060')));

    Http::fake([
        'graph.facebook.com/v25.0/222/leads*' => Http::response(['data' => [
            metaLead('8060'),
            metaLead('8061', ['full_name' => 'Backfill Person', 'phone_number' => '+919800000061']),
        ]]),
    ]);

    $this->actingAs($this->org->super)
        ->post(route('admin.integrations.facebook.forms.sync-leads', $this->meta->form), ['days' => 7])
        ->assertSessionHas('success');

    expect(Lead::count())->toBe(2)->and(LeadEnquiry::count())->toBe(2);
    expect(FacebookWebhookEvent::where('leadgen_id', '8061')->value('origin'))->toBe('sync');
    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return str_contains($request->url(), '/222/leads') && str_contains($query['filtering'] ?? '', 'time_created');
    });
    $this->assertDatabaseHas('audit_logs', ['action' => 'FACEBOOK_LEADS_SYNC_REQUESTED', 'entity_id' => $this->meta->form->id]);
});

test('backfill runs on the integrations queue and is capped at 90 days', function () {
    Queue::fake();

    $this->actingAs($this->org->super)->post(route('admin.integrations.facebook.forms.sync-leads', $this->meta->form), ['days' => 120])->assertSessionHasErrors('days');
    $this->actingAs($this->org->super)->post(route('admin.integrations.facebook.forms.sync-leads', $this->meta->form), ['days' => 30]);

    Queue::assertPushedOn('integrations', SyncMetaFormLeads::class);
});

test('meta:test-lead feeds a synthetic lead through the real pipeline locally and is idempotent', function () {
    $this->artisan('meta:test-lead --setup --leadgen=8070')->assertSuccessful();
    $this->artisan('meta:test-lead --leadgen=8070')->assertSuccessful();

    expect(Lead::where('facebook_lead_id', '8070')->count())->toBe(1)
        ->and(FacebookWebhookEvent::where('leadgen_id', '8070')->value('origin'))->toBe('test');
    Http::assertNothingSent();
});

// --- Release check H: queue retry recovers without duplication -----------------

test('RELEASE H: a crash after the lead was saved is recovered by retry without duplicates', function () {
    metaFakeGraph(['8080' => metaLead('8080')]);
    metaPost($this, metaPayload(metaChange('8080')));
    $event = FacebookWebhookEvent::sole();

    // Simulate a worker crash after commit but before the event was marked done.
    $event->forceFill(['processing_status' => FacebookEventStatus::Processing, 'processing_started_at' => now()->subHour(), 'lead_id' => null, 'lead_enquiry_id' => null, 'outcome' => null])->save();

    $this->artisan('meta:retry-failed')->assertSuccessful();

    expect(Lead::count())->toBe(1)
        ->and(LeadEnquiry::count())->toBe(1)
        ->and(LeadAssignment::count())->toBeLessThanOrEqual(1)
        ->and($event->fresh()->processing_status)->toBe(FacebookEventStatus::Duplicate)
        ->and($event->fresh()->lead_id)->toBe(Lead::sole()->id);
});
