<?php

use App\Enums\AuditAction;
use App\Enums\CallStatus;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Lead;
use App\Services\Telephony\CallService;
use App\Services\Telephony\TelephonyException;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->org = salesOrg();
    $this->integration = telephonySetup([$this->org->rahul, $this->org->priya, $this->org->manager]);
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create(['phone' => '98765 43210', 'normalized_phone' => '919876543210', 'alternate_phone' => '+91 99887 76655']);
});

test('a sales executive starts a click-to-call from a visible lead', function () {
    $response = $this->actingAs($this->org->rahul)->postJson(route('calls.store'), ['lead_id' => $this->lead->id, 'contact_field' => 'phone', 'mode' => 'pstn']);

    $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    $call = Call::sole();

    expect($call->call_number)->toMatch('/^CALL-\d{4}-000001$/')
        ->and($call->agent_user_id)->toBe($this->org->rahul->id)
        ->and($call->team_id)->toBeNull()
        ->and($call->lead_id)->toBe($this->lead->id)
        ->and($call->status)->toBe(CallStatus::Queued)
        ->and($call->customer_number_normalized)->toBe('919876543210')
        ->and($call->provider_call_id)->not->toBeNull();

    $request = fakeTelephony()->outbound[0];
    expect($request->customerNumber)->toBe('919876543210')
        ->and($request->agentNumber)->toBe('919000000001')
        ->and($request->reference)->toBe($call->client_reference)
        ->and($request->statusCallbackUrl)->toContain('/webhooks/telephony/fake/status');

    expect(Activity::where('type', 'call_started')->value('description'))->toBe('Rahul Sharma started an outbound call.');
    expect(AuditLog::where('action', AuditAction::CallInitiated->value)->exists())->toBeTrue();
    expect($response->json('call.status'))->toBe('queued')->and($response->json('dial'))->toBeNull();
});

test('call numbers are sequential', function () {
    $first = startCall($this->lead, $this->org->rahul);
    $second = startCall($this->lead, $this->org->rahul);

    expect((int) substr($second->call_number, -6))->toBe((int) substr($first->call_number, -6) + 1);
});

test('the alternate phone can be selected by field name', function () {
    startCall($this->lead, $this->org->rahul, ['contact_field' => 'alternate_phone']);

    expect(fakeTelephony()->outbound[0]->customerNumber)->toBe('919988776655')
        ->and(Call::sole()->contact_field)->toBe('alternate_phone');
});

test('the browser cannot send an arbitrary number without manual dial permission', function () {
    $this->actingAs($this->org->rahul)
        ->postJson(route('calls.store'), ['lead_id' => $this->lead->id, 'number' => '+91 90000 11111', 'mode' => 'pstn'])
        ->assertUnprocessable()->assertJsonValidationErrors('number');

    $this->actingAs($this->org->rahul)
        ->postJson(route('calls.store'), ['lead_id' => $this->lead->id, 'contact_field' => 'email'])
        ->assertUnprocessable()->assertJsonValidationErrors('contact_field');

    expect(Call::count())->toBe(0)->and(fakeTelephony()->outbound)->toBe([]);
});

test('manual dial works only with call.manual_dial', function () {
    $rahul = setPermission($this->org->rahul, 'call.manual_dial');

    $this->actingAs($rahul)->postJson(route('calls.store'), ['number' => '+91 90000 11111', 'mode' => 'pstn'])->assertCreated();

    expect(Call::sole()->contact_field)->toBe('manual')
        ->and(Call::sole()->lead_id)->toBeNull()
        ->and(fakeTelephony()->outbound[0]->customerNumber)->toBe('919000011111');
});

test('users cannot call leads they cannot see', function () {
    $foreign = Lead::factory()->assignedTo($this->org->outsider)->create();

    $this->actingAs($this->org->rahul)->postJson(route('calls.store'), ['lead_id' => $foreign->id, 'contact_field' => 'phone'])->assertNotFound();
    $this->actingAs($this->org->rahul)->postJson(route('calls.store'), ['lead_id' => Lead::factory()->assignedTo($this->org->rahul)->archived()->create()->id])->assertNotFound();

    expect(Call::count())->toBe(0);
});

test('a user without a calling account or with calling disabled cannot call', function () {
    $lead = Lead::factory()->assignedTo($this->org->outsider)->create();
    telephonySetup([], ['provider' => 'unused']);

    $this->actingAs($this->org->outsider)->postJson(route('calls.store'), ['lead_id' => $lead->id, 'contact_field' => 'phone'])
        ->assertUnprocessable()->assertJsonPath('message', 'Your calling account is not set up yet. Please contact your administrator.');

    $this->integration->forceFill(['is_active' => false])->save();
    $this->actingAs($this->org->rahul)->postJson(route('calls.store'), ['lead_id' => $this->lead->id, 'contact_field' => 'phone'])->assertUnprocessable();

    expect(Call::count())->toBe(0);
});

test('a deactivated user can no longer place calls', function () {
    $this->org->rahul->forceFill(['is_active' => false])->save();

    // The `active` middleware signs the user out before the controller runs...
    $this->actingAs($this->org->rahul)->postJson(route('calls.store'), ['lead_id' => $this->lead->id, 'contact_field' => 'phone'])->assertRedirect();
    // ...and CallService refuses independently.
    expect(fn () => startCall($this->lead, $this->org->rahul->fresh()))->toThrow(AuthorizationException::class)
        ->and(app(CallService::class)->availability($this->org->rahul->fresh())['enabled'])->toBeFalse();
    expect(Call::count())->toBe(0);
});

test('users without call.make are forbidden', function () {
    $rahul = setPermission($this->org->rahul, 'call.make', 'deny');

    $this->actingAs($rahul)->postJson(route('calls.store'), ['lead_id' => $this->lead->id])->assertForbidden();
    $this->actingAs($rahul)->postJson(route('telephony.session'))->assertForbidden();
});

test('a provider outage never creates a fake successful call', function () {
    fakeTelephony()->failWith(TelephonyException::unavailable('HTTP 503 from provider'));

    $this->actingAs($this->org->rahul)->postJson(route('calls.store'), ['lead_id' => $this->lead->id, 'contact_field' => 'phone', 'mode' => 'pstn'])
        ->assertStatus(503)->assertJsonPath('message', 'Calling service is temporarily unavailable.');

    expect(Call::count())->toBe(0)
        ->and($this->integration->fresh()->last_error)->toContain('HTTP 503');
});

test('browser calls are created first and return a dial instruction without contacting the provider', function () {
    $response = $this->actingAs($this->org->rahul)->postJson(route('calls.store'), ['lead_id' => $this->lead->id, 'contact_field' => 'phone', 'mode' => 'webrtc']);

    $response->assertCreated();
    $call = Call::sole();
    expect($call->channel->value)->toBe('webrtc')
        ->and($call->status)->toBe(CallStatus::Initiated)
        ->and($call->provider_call_id)->toBeNull()
        ->and($response->json('dial.reference'))->toBe($call->client_reference)
        ->and($response->json('dial.number'))->toBe('+919876543210')
        ->and(fakeTelephony()->outbound)->toBe([]);
});

test('the call lead of a completed call is updated as contacted, but not for busy calls', function () {
    $busy = finishCall($this, startCall($this->lead, $this->org->rahul), 'busy');
    expect($busy->status)->toBe(CallStatus::Busy)->and($this->lead->fresh()->last_contacted_at)->toBeNull();

    $done = finishCall($this, startCall($this->lead, $this->org->rahul), 'completed', 402);
    expect($done->status)->toBe(CallStatus::Completed)
        ->and($done->talk_duration_seconds)->toBe(402)
        ->and($done->requires_disposition)->toBeTrue()
        ->and($this->lead->fresh()->last_contacted_at)->not->toBeNull();

    expect(Activity::where('type', 'call_completed')->value('description'))->toBe('Outbound call completed — 6m 42s.');
});
