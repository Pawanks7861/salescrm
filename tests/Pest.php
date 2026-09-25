<?php

use App\Enums\FacebookIntegrationStatus;
use App\Models\Call;
use App\Models\FacebookForm;
use App\Models\FacebookIntegration;
use App\Models\FacebookPage;
use App\Models\Followup;
use App\Models\FollowupType;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LeadStatusChange;
use App\Models\LostReason;
use App\Models\Meeting;
use App\Models\MeetingType;
use App\Models\Permission;
use App\Models\PushSubscription;
use App\Models\Team;
use App\Models\TelephonyIntegration;
use App\Models\TelephonyNumber;
use App\Models\TelephonyUser;
use App\Models\User;
use App\Services\Followups\FollowupService;
use App\Services\Leads\LeadService;
use App\Services\Meetings\MeetingService;
use App\Services\Meta\MetaIntegrationService;
use App\Services\Notifications\PushTransport;
use App\Services\Notifications\WebPushService;
use App\Services\PermissionRegistrar;
use App\Services\Telephony\CallService;
use App\Services\Telephony\Providers\FakeTelephonyProvider;
use App\Services\Telephony\TelephonyManager;
use App\Support\CrmTime;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Standard sales organisation for lead tests:
 *  - Ahmedabad team managed by $manager with executives $rahul and $priya
 *  - Mumbai team managed by $otherManager with executive $outsider
 *  - $admin (admin role) and $super (super admin)
 */
function salesOrg(): object
{
    $manager = User::factory()->salesManager()->create(['name' => 'Mehul Manager']);
    $team = Team::create(['name' => 'Ahmedabad Team', 'manager_id' => $manager->id, 'is_active' => true]);
    $manager->forceFill(['team_id' => $team->id])->save();

    $rahul = User::factory()->salesExecutive()->create(['name' => 'Rahul Sharma', 'team_id' => $team->id, 'manager_id' => $manager->id]);
    $priya = User::factory()->salesExecutive()->create(['name' => 'Priya Patel', 'team_id' => $team->id, 'manager_id' => $manager->id]);

    $otherManager = User::factory()->salesManager()->create(['name' => 'Mumbai Manager']);
    $otherTeam = Team::create(['name' => 'Mumbai Team', 'manager_id' => $otherManager->id, 'is_active' => true]);
    $otherManager->forceFill(['team_id' => $otherTeam->id])->save();
    $outsider = User::factory()->salesExecutive()->create(['name' => 'Outside Exec', 'team_id' => $otherTeam->id, 'manager_id' => $otherManager->id]);

    $admin = User::factory()->admin()->create(['name' => 'Anita Admin']);
    $super = User::factory()->superAdmin()->create(['name' => 'Super Admin']);

    return (object) compact('manager', 'team', 'rahul', 'priya', 'otherManager', 'otherTeam', 'outsider', 'admin', 'super');
}

/** Minimal valid lead form payload. */
function leadPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Test',
        'last_name' => 'Lead',
        'phone' => '98'.random_int(10000000, 99999999),
        'email' => null,
        'priority' => 'medium',
        'source_id' => LeadSource::where('slug', 'manual')->value('id'),
    ], $overrides);
}

function leadStatusId(string $slug): int
{
    return (int) LeadStatus::where('slug', $slug)->value('id');
}

/** A wall-clock time in India, as a UTC instant. */
function ist(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Kolkata')->utc();
}

function nextFollowup(Lead $lead): ?string
{
    return $lead->fresh()->next_followup_at?->utc()->toDateTimeString();
}

function followupTypeId(string $slug = 'call'): int
{
    return (int) FollowupType::where('slug', $slug)->value('id');
}

/** Date + time fields for a UTC instant, expressed in the CRM timezone (Asia/Kolkata by default). */
function crmSlot(DateTimeInterface $at): array
{
    $local = CarbonImmutable::instance($at)->setTimezone(CrmTime::tz());

    return ['scheduled_date' => $local->format('Y-m-d'), 'scheduled_time' => $local->format('H:i')];
}

/** Valid create payload for `$lead`, tomorrow 11:00 CRM time unless overridden. */
function followupPayload(Lead $lead, array $overrides = []): array
{
    $tomorrow = CarbonImmutable::now(CrmTime::tz())->addDay();

    return array_merge([
        'lead_id' => $lead->id,
        'followup_type_id' => followupTypeId(),
        'scheduled_date' => $tomorrow->format('Y-m-d'),
        'scheduled_time' => '11:00',
        'priority' => 'medium',
        'reminder_minutes' => 15,
        'title' => 'Call back',
    ], $overrides);
}

function meetingTypeId(string $slug = 'product_demo'): int
{
    return (int) MeetingType::where('slug', $slug)->value('id');
}

/** Valid create payload (HTTP) for `$lead` (or none), tomorrow 10:00–11:00 CRM time unless overridden. */
function meetingPayload(?Lead $lead, array $overrides = []): array
{
    $tomorrow = CarbonImmutable::now(CrmTime::tz())->addDay();

    return array_merge([
        'lead_id' => $lead?->id,
        'meeting_type_id' => meetingTypeId(),
        'title' => 'Product demo',
        'scheduled_date' => $tomorrow->format('Y-m-d'),
        'start_time' => '10:00',
        'end_time' => '11:00',
        'location_type' => 'office',
        'priority' => 'medium',
        'reminders' => [30],
    ], $overrides);
}

/** Creates a meeting through MeetingService as `$actor` (tomorrow 10:00–11:00 unless overridden). */
function scheduleMeeting(?Lead $lead, User $actor, array $overrides = [], bool $override = false): Meeting
{
    $previous = Auth::user();
    Auth::setUser($actor);

    try {
        return app(MeetingService::class)
            ->create($lead, collect(meetingPayload($lead, $overrides))->except('lead_id')->all(), $actor, $override);
    } finally {
        $previous ? Auth::setUser($previous) : Auth::forgetUser();
    }
}

/*
| Meta / Facebook Lead Ads helpers. All Graph traffic is faked with Http::fake;
| the secrets below are test-only values set per test.
*/
const META_TEST_SECRET = 'test-app-secret-value';
const META_TEST_VERIFY = 'test-verify-token-value';
const META_TEST_USER_TOKEN = 'EAATESTUSERTOKEN1234567890abcdef';
const META_TEST_PAGE_TOKEN = 'EAATESTPAGETOKEN1234567890abcdef';

function metaConfigure(): void
{
    config([
        'meta.app_id' => '123456789012345',
        'meta.app_secret' => META_TEST_SECRET,
        'meta.webhook_verify_token' => META_TEST_VERIFY,
        'meta.graph_version' => 'v25.0',
        'meta.graph_url' => 'https://graph.facebook.com',
    ]);
}

/** Connected integration + receiving Page 111 + enabled form 222 (standard questions). */
function metaSetup(array $form = [], array $page = []): object
{
    metaConfigure();

    $integration = new FacebookIntegration;
    $integration->forceFill([
        'app_id' => '123456789012345',
        'access_token_encrypted' => META_TEST_USER_TOKEN,
        'token_type' => 'user',
        'status' => FacebookIntegrationStatus::Connected,
        'facebook_user_id' => '999',
        'facebook_user_name' => 'Meta Admin',
        'graph_version' => 'v25.0',
        'granted_scopes_json' => MetaIntegrationService::REQUIRED_SCOPES,
        'missing_scopes_json' => [],
        'last_connected_at' => now(),
    ])->save();

    $fbPage = new FacebookPage;
    $fbPage->forceFill(array_merge([
        'facebook_integration_id' => $integration->id,
        'page_id' => '111',
        'page_name' => 'Acme Homes',
        'page_access_token_encrypted' => META_TEST_PAGE_TOKEN,
        'is_selected' => true,
        'is_subscribed' => true,
        'is_active' => true,
    ], $page))->save();

    $fbForm = new FacebookForm;
    $fbForm->forceFill(array_merge([
        'facebook_page_id' => $fbPage->id,
        'form_id' => '222',
        'form_name' => 'Home Loan Enquiry',
        'status' => 'ACTIVE',
        'is_enabled' => true,
        'questions_json' => [
            ['key' => 'full_name', 'label' => 'Full name', 'type' => 'FULL_NAME'],
            ['key' => 'email', 'label' => 'Email', 'type' => 'EMAIL'],
            ['key' => 'phone_number', 'label' => 'Phone number', 'type' => 'PHONE'],
            ['key' => 'city', 'label' => 'City', 'type' => 'CITY'],
            ['key' => 'budget', 'label' => 'What is your budget?', 'type' => 'CUSTOM'],
        ],
    ], $form))->save();

    return (object) ['integration' => $integration, 'page' => $fbPage, 'form' => $fbForm];
}

function metaSign(string $raw, string $secret = META_TEST_SECRET): string
{
    return 'sha256='.hash_hmac('sha256', $raw, $secret);
}

function metaChange(string $leadgenId, string $pageId = '111', ?string $formId = '222'): array
{
    return ['field' => 'leadgen', 'value' => array_filter([
        'leadgen_id' => $leadgenId, 'page_id' => $pageId, 'form_id' => $formId,
        'ad_id' => '555', 'adgroup_id' => '556', 'created_time' => 1790000000,
    ])];
}

function metaPayload(array ...$changes): array
{
    return ['object' => 'page', 'entry' => [['id' => '111', 'time' => 1790000000, 'changes' => $changes]]];
}

/** Graph lead node as returned by GET /{leadgen_id}. */
function metaLead(string $leadgenId, array $answers = [], array $overrides = []): array
{
    $answers = $answers ?: ['full_name' => 'Amit Desai', 'email' => 'amit.desai@example.com', 'phone_number' => '+919812345678', 'city' => 'Pune', 'budget' => '25-50 lakh'];

    return array_merge([
        'id' => $leadgenId,
        'created_time' => '2026-09-24T10:00:00+0000',
        'form_id' => '222',
        'ad_id' => '555', 'ad_name' => 'Ad A',
        'adset_id' => '556', 'adset_name' => 'Ad Set A',
        'campaign_id' => '120210000000777', 'campaign_name' => 'Diwali Loans',
        'is_organic' => false,
        'platform' => 'fb',
        'field_data' => collect($answers)->map(fn ($v, $k) => ['name' => $k, 'values' => (array) $v])->values()->all(),
    ], $overrides);
}

/** Fakes Graph responses for the given lead nodes (keyed by leadgen_id) plus `$extra` patterns. */
function metaFakeGraph(array $leads = [], array $extra = []): void
{
    $fakes = [];
    foreach ($leads as $id => $response) {
        $fakes["graph.facebook.com/v25.0/{$id}*"] = $response instanceof Closure || $response instanceof PromiseInterface
            ? $response
            : Http::response($response);
    }

    Http::fake(array_merge($extra, $fakes));
}

/** Posts a raw webhook body exactly as Meta would. */
function metaPost(Illuminate\Foundation\Testing\TestCase $test, array|string $payload, ?string $signature = null, bool $sign = true): TestResponse
{
    $raw = is_string($payload) ? $payload : json_encode($payload);
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($sign) {
        $server['HTTP_X_HUB_SIGNATURE_256'] = $signature ?? metaSign($raw);
    }

    return $test->call('POST', '/webhooks/meta/leads', [], [], [], $server, $raw);
}

/*
| Telephony helpers. phpunit.xml sets TELEPHONY_DRIVER=fake; the fake provider
| never touches the network and is shared through the TelephonyManager singleton.
*/
const TELEPHONY_TEST_TOKEN = 'local-fake-callback-token';

/** Per-user permission override ('grant' | 'deny'), cache flushed. */
function setPermission(User $user, string $permission, string $type = 'grant'): User
{
    $user->permissionOverrides()->syncWithoutDetaching([Permission::where('name', $permission)->value('id') => ['type' => $type]]);
    app(PermissionRegistrar::class)->flushUser($user);

    return $user->fresh();
}

/**
 * Simulates a pre-upgrade database: recreates a deprecated permission row
 * (e.g. lead.view_team) and grants it through the user's role.
 */
function legacyRoleGrant(User $user, string $permission): User
{
    $row = Permission::firstOrCreate(['name' => $permission], ['label' => $permission, 'module' => 'Deprecated']);
    $user->role->permissions()->syncWithoutDetaching([$row->id]);
    app(PermissionRegistrar::class)->flushAll();

    return $user->fresh();
}

/**
 * Active fake integration (PSTN + browser + recording), a default number and a
 * calling account for each given user (registered phone 9190000000NN).
 */
function telephonySetup(array $users = [], array $integration = []): TelephonyIntegration
{
    $row = new TelephonyIntegration;
    $row->forceFill(array_merge([
        'provider' => 'fake',
        'name' => 'Local simulator',
        'is_active' => true,
        'browser_calling_enabled' => true,
        'pstn_calling_enabled' => true,
        'recording_enabled' => true,
        'default_calling_mode' => 'pstn',
    ], $integration))->save();

    $number = new TelephonyNumber;
    $number->forceFill([
        'integration_id' => $row->id, 'phone_number' => '+918000000001', 'normalized_number' => '918000000001',
        'display_name' => 'Sales line', 'is_active' => true, 'is_default' => true,
    ])->save();

    foreach (array_values($users) as $i => $user) {
        telephonyAgent($row, $user, ['registered_phone' => sprintf('+9190000000%02d', $i + 1)]);
    }

    return $row;
}

function telephonyAgent(TelephonyIntegration $integration, User $user, array $overrides = []): TelephonyUser
{
    $phone = $overrides['registered_phone'] ?? '+919000000099';
    $agent = new TelephonyUser;
    $agent->forceFill(array_merge([
        'integration_id' => $integration->id,
        'user_id' => $user->id,
        'provider_user_id' => 'agent-'.$user->id,
        'provider_sip_username' => 'sip'.$user->id,
        'registered_phone' => $phone,
        'registered_phone_normalized' => preg_replace('/\D/', '', $phone),
        'calling_mode' => 'pstn',
        'is_enabled' => true,
    ], $overrides))->save();

    return $agent;
}

function fakeTelephony(): FakeTelephonyProvider
{
    return app(TelephonyManager::class)->provider();
}

/** Posts a fake-provider callback exactly as the simulator would. */
function telephonyCallback(Illuminate\Foundation\Testing\TestCase $test, array $payload, string $endpoint = 'status', ?string $token = TELEPHONY_TEST_TOKEN): TestResponse
{
    $query = $token === null ? '' : '?token='.urlencode($token);

    return $test->postJson("/webhooks/telephony/fake/{$endpoint}{$query}", $payload);
}

/** Starts an outbound PSTN call through CallService as `$agent` and returns the Call. */
function startCall(Lead $lead, User $agent, array $input = []): Call
{
    return app(CallService::class)->startOutbound($agent, $lead, array_merge(['contact_field' => 'phone', 'mode' => 'pstn'], $input))['call'];
}

/** Drives a call to a terminal state via provider callbacks. */
function finishCall(Illuminate\Foundation\Testing\TestCase $test, Call $call, string $status = 'completed', int $talk = 402, ?string $recording = null): Call
{
    $sid = $call->provider_call_id ?? 'FAKE-'.$call->id;
    if ($status === 'completed') {
        telephonyCallback($test, ['call_id' => $sid, 'reference' => $call->client_reference, 'status' => 'answered'])->assertOk();
    }
    telephonyCallback($test, array_filter([
        'call_id' => $sid,
        'reference' => $call->client_reference,
        'status' => $status,
        'talk_seconds' => $status === 'completed' ? $talk : null,
        'recording_url' => $recording,
    ], fn ($v) => $v !== null))->assertOk();

    return $call->fresh();
}

/*
| Reporting helpers.
*/

/** Factory lead owned by `$owner` with its initial status-history row (as LeadService would write). */
function reportLead(?User $owner, array $attributes = [], string $status = 'new'): Lead
{
    $factory = Lead::factory()->status($status);
    $lead = ($owner ? $factory->assignedTo($owner) : $factory)->create($attributes);
    LeadStatusChange::record($lead, null, (int) $lead->status_id, $owner?->id, $lead->created_at);

    return $lead;
}

/** Moves a lead through LeadService (records history at the current test time). */
function moveLead(Lead $lead, string $slug, User $actor): Lead
{
    $lostReason = $slug === 'lost' ? (int) LostReason::query()->value('id') : null;
    app(LeadService::class)->changeStatus($lead->fresh(), leadStatusId($slug), $actor, $lostReason);

    return $lead->fresh();
}

/** Inertia props of a report page rendered for `$user`. */
function reportProps(Illuminate\Foundation\Testing\TestCase $test, User $user, string $slug, array $query = []): array
{
    $response = $test->actingAs($user)->get('/reports/'.$slug.($query ? '?'.http_build_query($query) : ''));
    $response->assertOk();

    return $response->viewData('page')['props'];
}

function reportSection(array $props, string $key): ?array
{
    return collect($props['sections'])->firstWhere('key', $key);
}

function reportKpi(array $props, string $section, string $key): mixed
{
    $item = collect(reportSection($props, $section)['items'] ?? [])->firstWhere('key', $key);
    expect($item)->not->toBeNull("KPI {$section}.{$key} missing");

    return $item['value'];
}

/** @return array<int, array> table rows keyed by nothing; use ->firstWhere('name', …) */
function reportRows(array $props, string $table): Collection
{
    $section = reportSection($props, $table);
    expect($section)->not->toBeNull("Table {$table} missing");

    return collect($section['rows']);
}

/*
| Browser push helpers. Test-only VAPID values; the transport is faked so no
| request ever leaves the process.
*/
function pushConfigure(): void
{
    config([
        'webpush.vapid.public_key' => 'BTestPublicKey_'.str_repeat('A', 72),
        'webpush.vapid.private_key' => 'test-private-'.str_repeat('B', 30),
        'webpush.vapid.subject' => 'mailto:test@example.com',
    ]);
}

function pushEndpoint(string $device): string
{
    return 'https://fcm.googleapis.com/fcm/send/'.$device.'-token';
}

function pushBody(string $device): array
{
    return [
        'endpoint' => pushEndpoint($device),
        'keys' => ['p256dh' => 'BPublicKeyForTests_'.str_repeat('x', 60), 'auth' => 'authSecret'.strlen($device)],
        'content_encoding' => 'aes128gcm',
    ];
}

function pushSubscribe(User $user, string $device): PushSubscription
{
    $body = pushBody($device);

    return app(WebPushService::class)
        ->subscribe($user, $body['endpoint'], $body['keys']['p256dh'], $body['keys']['auth'], 'aes128gcm', 'Pest');
}

function pushOptIn(User $user, bool $sound = true): User
{
    $user->forceFill(['browser_notifications_enabled' => true, 'notification_sound_enabled' => $sound])->save();

    return $user->fresh();
}

/**
 * Records every send; `$results` maps subscription id => {ok, expired, status}
 * (default: accepted).
 */
function pushFakeTransport(array $results = []): object
{
    $fake = new class($results) implements PushTransport
    {
        public array $sent = [];

        public function __construct(private array $results) {}

        public function send(iterable $subscriptions, string $payload): array
        {
            $ids = collect($subscriptions)->pluck('id')->all();
            $this->sent[] = ['subscription_ids' => $ids, 'payload' => json_decode($payload, true)];

            return collect($ids)->mapWithKeys(fn ($id) => [$id => $this->results[$id] ?? ['ok' => true, 'expired' => false, 'status' => 201]])->all();
        }
    };
    app()->instance(PushTransport::class, $fake);

    return $fake;
}

/** Creates a follow-up through FollowupService as `$actor` (tomorrow 11:00 unless overridden). */
function scheduleFollowup(Lead $lead, User $actor, array $overrides = []): Followup
{
    $previous = Auth::user();
    Auth::setUser($actor);

    try {
        return app(FollowupService::class)
            ->create($lead, collect(followupPayload($lead, $overrides))->except('lead_id')->all(), $actor, true);
    } finally {
        $previous ? Auth::setUser($previous) : Auth::forgetUser();
    }
}
