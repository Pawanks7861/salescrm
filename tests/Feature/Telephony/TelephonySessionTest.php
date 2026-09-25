<?php

use App\Enums\CallStatus;
use App\Models\Call;
use App\Models\CallEvent;
use App\Models\Lead;
use App\Models\TelephonyUser;
use App\Services\Telephony\Data\ProviderCallEvent;
use App\Services\Telephony\TelephonyException;
use App\Services\Telephony\TelephonyManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->org = salesOrg();
    $this->integration = telephonySetup([$this->org->rahul, $this->org->priya], ['default_calling_mode' => 'webrtc']);
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create(['phone' => '9876543210', 'normalized_phone' => '919876543210']);
});

test('the softphone config contains no credentials', function () {
    TelephonyUser::where('user_id', $this->org->rahul->id)->update(['calling_mode' => 'webrtc']);

    $response = $this->actingAs($this->org->rahul)->getJson(route('telephony.config'))
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('default_mode', 'webrtc')
        ->assertJsonPath('recording', true);

    expect($response->json('modes'))->toContain('webrtc', 'pstn')
        ->and($response->getContent())->not->toContain('session_token')
        ->and($response->getContent())->not->toContain('access_token')
        ->and($response->getContent())->not->toContain(TELEPHONY_TEST_TOKEN);
});

test('a mapped agent receives a transient, uncached browser session', function () {
    $response = $this->actingAs($this->org->rahul)->postJson(route('telephony.session'))
        ->assertOk()
        ->assertHeader('Pragma', 'no-cache')
        ->assertJsonPath('driver', 'fake')
        ->assertJsonPath('credentials.user_id', 'agent-'.$this->org->rahul->id);

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(TelephonyUser::where('user_id', $this->org->rahul->id)->value('last_registered_at'))->not->toBeNull();
});

test('each agent gets their own provider identity', function () {
    $rahul = $this->actingAs($this->org->rahul)->postJson(route('telephony.session'))->json('credentials.user_id');
    $priya = $this->actingAs($this->org->priya)->postJson(route('telephony.session'))->json('credentials.user_id');

    expect($rahul)->not->toBe($priya);
});

test('a browser session requires a calling account', function () {
    $this->actingAs($this->org->manager)->postJson(route('telephony.session'))
        ->assertUnprocessable()->assertJsonPath('message', 'Your calling account is not set up yet. Please contact your administrator.');
});

test('a browser session requires browser calling to be enabled', function () {
    $this->integration->forceFill(['browser_calling_enabled' => false])->save();

    $this->actingAs($this->org->rahul)->postJson(route('telephony.session'))->assertUnprocessable();
});

test('a disabled calling account or an inactive integration blocks the session', function () {
    TelephonyUser::where('user_id', $this->org->rahul->id)->update(['is_enabled' => false]);
    $this->actingAs($this->org->rahul)->postJson(route('telephony.session'))->assertUnprocessable();

    $this->integration->forceFill(['is_active' => false])->save();
    $this->actingAs($this->org->priya)->postJson(route('telephony.session'))->assertUnprocessable();
    $this->actingAs($this->org->priya)->getJson(route('telephony.config'))->assertOk()->assertJsonPath('enabled', false);
});

test('users without call.make cannot obtain a session', function () {
    setPermission($this->org->rahul, 'call.make', 'deny');

    $this->actingAs($this->org->rahul->fresh())->postJson(route('telephony.session'))->assertForbidden();
});

test('the fake driver cannot be resolved in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(TelephonyManager::fakeAllowed())->toBeFalse()
        ->and(fn () => (new TelephonyManager)->provider())->toThrow(RuntimeException::class, 'only available in local/testing');
});

test('the fake simulator endpoints are unreachable in production', function () {
    $call = startCall($this->lead, $this->org->rahul);
    app()->detectEnvironment(fn () => 'production');
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->actingAs($this->org->rahul)->postJson(route('telephony.fake.simulate', $call), ['status' => 'answered'])->assertNotFound();
    $this->actingAs($this->org->rahul)->postJson(route('telephony.fake.incoming'), ['number' => '+919876543210'])->assertNotFound();

    expect($call->fresh()->status)->toBe(CallStatus::Queued);
});

test('the fake simulator only drives the current user own calls', function () {
    $call = startCall($this->lead, $this->org->rahul);

    $this->actingAs($this->org->manager)->postJson(route('telephony.fake.simulate', $call), ['status' => 'answered'])->assertForbidden();
    $this->actingAs($this->org->rahul)->postJson(route('telephony.fake.simulate', $call), ['status' => 'answered'])->assertOk();

    expect($call->fresh()->status)->toBe(CallStatus::Answered);
});

test('reconciliation closes stale calls from provider details', function () {
    $call = startCall($this->lead, $this->org->rahul);
    $call->forceFill(['started_at' => now()->subMinutes(30)])->save();
    fakeTelephony()->calls[$call->provider_call_id] = new ProviderCallEvent(
        type: ProviderCallEvent::DETAILS, providerCallId: $call->provider_call_id, status: CallStatus::Completed,
        providerStatus: 'completed', answeredAt: CarbonImmutable::now()->subMinutes(29), endedAt: CarbonImmutable::now()->subMinutes(25), talkSeconds: 240,
    );

    $this->artisan('telephony:reconcile-pending')->assertSuccessful();

    $fresh = $call->fresh();
    expect($fresh->status)->toBe(CallStatus::Completed)
        ->and($fresh->talk_duration_seconds)->toBe(240)
        ->and($fresh->reconcile_attempts)->toBe(1);
});

test('reconciliation leaves recent calls alone and closes abandoned ones', function () {
    $recent = startCall($this->lead, $this->org->rahul);
    $abandoned = startCall($this->lead, $this->org->rahul);
    $abandoned->forceFill(['started_at' => now()->subHours(30)])->save();

    $this->artisan('telephony:reconcile-pending')->assertSuccessful();

    expect($recent->fresh()->status)->toBe(CallStatus::Queued)
        ->and($abandoned->fresh()->status)->toBe(CallStatus::Failed)
        ->and($abandoned->fresh()->failure_code)->toBe('reconcile_timeout');
});

test('reconciliation stops early during a provider outage without changing calls', function () {
    $call = startCall($this->lead, $this->org->rahul);
    $call->forceFill(['started_at' => now()->subMinutes(30)])->save();
    fakeTelephony()->failWith(TelephonyException::unavailable('down'));

    $this->artisan('telephony:reconcile-pending')->assertSuccessful();

    expect($call->fresh()->status)->toBe(CallStatus::Queued)->and($call->fresh()->reconcile_attempts)->toBe(1);
});

test('pruning removes old processed callback events but keeps calls', function () {
    $call = startCall($this->lead, $this->org->rahul);
    finishCall($this, $call);
    CallEvent::query()->update(['received_at' => now()->subDays(200)]);

    $this->artisan('telephony:prune')->assertSuccessful();

    expect(CallEvent::count())->toBe(0)->and(Call::count())->toBe(1);
});
