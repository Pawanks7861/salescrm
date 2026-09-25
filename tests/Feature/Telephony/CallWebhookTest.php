<?php

use App\Enums\AuditAction;
use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\CallEvent;
use App\Models\Lead;
use App\Notifications\Calls\MissedCallNotification;
use App\Services\Leads\LeadAssignmentService;
use App\Services\SettingService;
use App\Services\Telephony\CallService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->org = salesOrg();
    $this->integration = telephonySetup([$this->org->rahul, $this->org->priya]);
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create(['full_name' => 'Amit Desai', 'phone' => '9876543210', 'normalized_phone' => '919876543210']);
});

test('callbacks without the secret token are rejected and audited', function () {
    $call = startCall($this->lead, $this->org->rahul);

    telephonyCallback($this, ['call_id' => $call->provider_call_id, 'status' => 'completed'], 'status', null)->assertForbidden();
    telephonyCallback($this, ['call_id' => $call->provider_call_id, 'status' => 'completed'], 'status', 'wrong-token')->assertForbidden();

    expect($call->fresh()->status)->toBe(CallStatus::Queued)
        ->and(AuditLog::where('action', AuditAction::CallWebhookRejected->value)->exists())->toBeTrue()
        ->and(CallEvent::count())->toBe(0);
});

test('callbacks for another provider path are not accepted', function () {
    $this->postJson('/webhooks/telephony/exotel/status?token='.TELEPHONY_TEST_TOKEN, ['call_id' => 'x', 'status' => 'completed'])->assertNotFound();
});

test('webhook responses are plain text, never HTML', function () {
    $response = telephonyCallback($this, ['call_id' => 'x', 'status' => 'completed'], 'status', 'bad');

    expect($response->getContent())->not->toContain('<html');
});

test('the full lifecycle is driven by provider callbacks', function () {
    $call = startCall($this->lead, $this->org->rahul);
    $sid = $call->provider_call_id;

    telephonyCallback($this, ['call_id' => $sid, 'status' => 'ringing', 'event_id' => 'e1'])->assertOk()->assertSee('OK');
    expect($call->fresh()->status)->toBe(CallStatus::Ringing)->and($call->fresh()->ringing_at)->not->toBeNull();

    telephonyCallback($this, ['call_id' => $sid, 'status' => 'answered', 'event_id' => 'e2'])->assertOk();
    expect($call->fresh()->status)->toBe(CallStatus::Answered)->and($call->fresh()->answered_at)->not->toBeNull();

    telephonyCallback($this, ['call_id' => $sid, 'status' => 'completed', 'event_id' => 'e3', 'talk_seconds' => 125, 'ring_seconds' => 8])->assertOk();
    $fresh = $call->fresh();

    expect($fresh->status)->toBe(CallStatus::Completed)
        ->and($fresh->talk_duration_seconds)->toBe(125)
        ->and($fresh->ended_at)->not->toBeNull()
        ->and($fresh->durationLabel())->toBe('2m 5s')
        ->and(CallEvent::where('call_id', $call->id)->count())->toBe(3)
        ->and($this->integration->fresh()->last_callback_at)->not->toBeNull()
        ->and(AuditLog::where('action', AuditAction::CallCompleted->value)->exists())->toBeTrue();
});

test('duplicate callbacks are processed once', function () {
    $call = startCall($this->lead, $this->org->rahul);
    $payload = ['call_id' => $call->provider_call_id, 'status' => 'completed', 'event_id' => 'dup-1', 'talk_seconds' => 60];

    telephonyCallback($this, $payload)->assertOk()->assertSee('OK');
    telephonyCallback($this, $payload)->assertOk()->assertSee('DUPLICATE');

    expect(CallEvent::count())->toBe(1)
        ->and(Activity::where('type', 'call_completed')->count())->toBe(1);
});

test('out-of-order callbacks never downgrade a call', function () {
    $call = startCall($this->lead, $this->org->rahul);
    $sid = $call->provider_call_id;

    telephonyCallback($this, ['call_id' => $sid, 'status' => 'completed', 'event_id' => 'a', 'talk_seconds' => 30])->assertOk();
    telephonyCallback($this, ['call_id' => $sid, 'status' => 'ringing', 'event_id' => 'b'])->assertOk();
    telephonyCallback($this, ['call_id' => $sid, 'status' => 'answered', 'event_id' => 'c'])->assertOk();
    telephonyCallback($this, ['call_id' => $sid, 'status' => 'failed', 'event_id' => 'd'])->assertOk();

    expect($call->fresh()->status)->toBe(CallStatus::Completed)->and($call->fresh()->talk_duration_seconds)->toBe(30);
});

test('status callbacks for unknown calls are ignored and never create records', function () {
    telephonyCallback($this, ['call_id' => 'NOPE', 'status' => 'completed', 'direction' => 'outbound'])->assertOk();

    expect(Call::count())->toBe(0)->and(CallEvent::sole()->processing_status->value)->toBe('ignored');
});

test('a browser call is matched by the CRM reference and gets the provider id attached', function () {
    $call = app(CallService::class)->startOutbound($this->org->rahul, $this->lead, ['contact_field' => 'phone', 'mode' => 'webrtc'])['call'];

    telephonyCallback($this, ['call_id' => 'SDK-123', 'reference' => $call->client_reference, 'status' => 'ringing'])->assertOk();

    expect($call->fresh()->provider_call_id)->toBe('SDK-123')->and($call->fresh()->status)->toBe(CallStatus::Ringing);
});

test('an incoming call from a known number is linked to the lead and its owner', function () {
    telephonyCallback($this, ['call_id' => 'IN-1', 'status' => 'incoming', 'from' => '+91 98765 43210', 'to' => '+918000000001'], 'passthru')->assertOk();

    $call = Call::sole();
    expect($call->direction)->toBe(CallDirection::Inbound)
        ->and($call->lead_id)->toBe($this->lead->id)
        ->and($call->agent_user_id)->toBe($this->org->rahul->id)
        ->and($call->team_id)->toBeNull()
        ->and($call->telephony_number_id)->not->toBeNull();
});

test('an incoming call is assigned to the agent whose leg was dialled', function () {
    telephonyCallback($this, ['call_id' => 'IN-2', 'status' => 'incoming', 'from' => '+91 98765 43210', 'agent_number' => '+919000000002'], 'passthru')->assertOk();

    expect(Call::sole()->agent_user_id)->toBe($this->org->priya->id);
});

test('an incoming call from an unknown number creates no lead', function () {
    telephonyCallback($this, ['call_id' => 'IN-3', 'status' => 'incoming', 'from' => '+91 91111 22222'], 'passthru')->assertOk();

    expect(Call::sole()->lead_id)->toBeNull()->and(Lead::count())->toBe(1);
});

test('a missed incoming call notifies the lead owner and records the activity', function () {
    Notification::fake();

    telephonyCallback($this, ['call_id' => 'IN-4', 'status' => 'incoming', 'from' => '+91 98765 43210'], 'passthru')->assertOk();
    telephonyCallback($this, ['call_id' => 'IN-4', 'status' => 'missed'])->assertOk();

    expect(Call::sole()->status)->toBe(CallStatus::Missed)
        ->and(Activity::where('type', 'call_missed')->value('description'))->toBe('Incoming call missed.')
        ->and($this->lead->fresh()->last_contacted_at)->toBeNull();

    Notification::assertSentTo($this->org->rahul, MissedCallNotification::class, function ($n) {
        return str_starts_with($n->toArray($this->org->rahul)['message'], 'Missed call from Amit Desai at ');
    });
    Notification::assertNotSentTo($this->org->priya, MissedCallNotification::class);
});

test('missed call notifications can be switched off', function () {
    Notification::fake();
    app(SettingService::class)->updateGroup('telephony', ['telephony.notify_missed_calls' => false]);

    telephonyCallback($this, ['call_id' => 'IN-5', 'status' => 'incoming', 'from' => '+91 98765 43210'], 'passthru')->assertOk();
    telephonyCallback($this, ['call_id' => 'IN-5', 'status' => 'missed'])->assertOk();

    Notification::assertNothingSent();
});

test('oversized payloads are rejected', function () {
    telephonyCallback($this, ['call_id' => 'x', 'status' => 'completed', 'junk' => str_repeat('a', 300 * 1024)])->assertStatus(413);
});

test('callback payloads are stored encrypted and phone numbers are not logged', function () {
    Log::spy();

    telephonyCallback($this, ['call_id' => 'IN-6', 'status' => 'incoming', 'from' => '+91 98765 43210'], 'passthru')->assertOk();

    $raw = DB::table('call_events')->value('payload_encrypted');
    expect($raw)->not->toContain('9876543210');

    Log::shouldNotHaveReceived('info', [Mockery::on(fn ($m) => str_contains((string) $m, '9876543210'))]);
    Log::shouldNotHaveReceived('warning', [Mockery::on(fn ($m) => str_contains((string) $m, '9876543210')), Mockery::any()]);
});

test('the historical agent is not rewritten when the lead is reassigned, and no team is stamped', function () {
    $call = finishCall($this, startCall($this->lead, $this->org->rahul));
    app(LeadAssignmentService::class)->assignManually($this->lead, $this->org->admin, $this->org->outsider->id);

    expect($this->lead->fresh()->assigned_to)->toBe($this->org->outsider->id)
        ->and($call->fresh()->agent_user_id)->toBe($this->org->rahul->id)
        ->and($call->fresh()->team_id)->toBeNull();
});
