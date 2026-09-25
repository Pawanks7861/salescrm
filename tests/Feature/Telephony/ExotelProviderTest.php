<?php

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\TelephonyUser;
use App\Services\Telephony\Data\OutboundCallRequest;
use App\Services\Telephony\Providers\ExotelTelephonyProvider;
use App\Services\Telephony\TelephonyException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const EXOTEL_TEST_KEY = 'test-api-key-123';
const EXOTEL_TEST_TOKEN = 'test-api-token-456';

function exotel(array $overrides = []): ExotelTelephonyProvider
{
    return new ExotelTelephonyProvider(array_merge([
        'account_sid' => 'acme1',
        'api_key' => EXOTEL_TEST_KEY,
        'api_token' => EXOTEL_TEST_TOKEN,
        'subdomain' => 'api.exotel.com',
        'webhook_secret' => 'hook-secret',
        'webhook_allowed_ips' => [],
        'recording_hosts' => ['exotel.com', 'exotel.in', 'amazonaws.com'],
        'timezone' => 'Asia/Kolkata',
    ], $overrides), ['connect_timeout' => 1, 'timeout' => 2]);
}

function exotelOutbound(): OutboundCallRequest
{
    return new OutboundCallRequest('919000000001', '919876543210', '08000000001', 'crm-ref-1', true, 'https://crm.test/webhooks/telephony/exotel/status?token=hook-secret');
}

function exotelCallback(array $data, array $query = ['token' => 'hook-secret'], string $ip = '10.0.0.1'): Request
{
    $request = Request::create('/webhooks/telephony/exotel/status?'.http_build_query($query), 'POST', [], [], [], ['REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json'], json_encode($data));

    return $request;
}

test('click-to-call posts to connect.json with basic auth and the documented parameters', function () {
    Http::fake(['api.exotel.com/*' => Http::response(['Call' => ['Sid' => 'EXO-SID-1', 'Status' => 'in-progress']])]);

    $result = exotel()->initiateOutboundCall(exotelOutbound());

    expect($result->providerCallId)->toBe('EXO-SID-1')->and($result->status)->toBe(CallStatus::Answered);

    Http::assertSent(function (HttpRequest $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.exotel.com/v1/Accounts/acme1/Calls/connect.json'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode(EXOTEL_TEST_KEY.':'.EXOTEL_TEST_TOKEN))
            && ! str_contains($request->url(), EXOTEL_TEST_TOKEN)
            && $request['From'] === '+919000000001'
            && $request['To'] === '+919876543210'
            && $request['CallerId'] === '08000000001'
            && $request['CustomField'] === 'crm-ref-1'
            && $request['Record'] === 'true'
            && $request['StatusCallbackContentType'] === 'application/json';
    });
});

test('provider errors are translated and never expose credentials', function (int $status, string $category) {
    Log::spy();
    Http::fake(['api.exotel.com/*' => Http::response(['RestException' => ['Message' => 'Bad number +91 98765 43210 key '.EXOTEL_TEST_KEY]], $status)]);

    try {
        exotel()->initiateOutboundCall(exotelOutbound());
        $this->fail('Expected TelephonyException');
    } catch (TelephonyException $e) {
        expect($e->category)->toBe($category)
            ->and($e->getMessage())->not->toContain(EXOTEL_TEST_TOKEN)
            ->and($e->getMessage())->not->toContain('98765');
    }

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => ! str_contains(json_encode($context), EXOTEL_TEST_TOKEN) && ! str_contains(json_encode($context), '98765'));
})->with([
    'auth failure' => [401, TelephonyException::NOT_CONFIGURED],
    'server error' => [503, TelephonyException::UNAVAILABLE],
    'rate limited' => [429, TelephonyException::UNAVAILABLE],
    'bad request' => [400, TelephonyException::REJECTED],
]);

test('connection failures become unavailable', function () {
    $provider = exotel(['subdomain' => '127.0.0.1:9']);

    expect(fn () => $provider->initiateOutboundCall(exotelOutbound()))->toThrow(TelephonyException::class);
});

test('an unconfigured provider refuses to call and reports not configured on health check', function () {
    Http::fake();
    $provider = exotel(['api_token' => '']);

    expect($provider->isConfigured())->toBeFalse()
        ->and(fn () => $provider->initiateOutboundCall(exotelOutbound()))->toThrow(TelephonyException::class)
        ->and($provider->healthCheck()->status)->toBe('not_configured');
    Http::assertNothingSent();
});

test('health check reports auth failures', function () {
    Http::fake(['api.exotel.com/*' => Http::response([], 401)]);

    expect(exotel()->healthCheck()->status)->toBe('auth_failed');
});

test('callbacks require the secret token and a matching account', function () {
    $provider = exotel();

    expect($provider->validateWebhook(exotelCallback(['CallSid' => 'x']))->valid)->toBeTrue()
        ->and($provider->validateWebhook(exotelCallback(['CallSid' => 'x'], []))->reason)->toBe('invalid_token')
        ->and($provider->validateWebhook(exotelCallback(['CallSid' => 'x'], ['token' => 'wrong']))->reason)->toBe('invalid_token')
        ->and($provider->validateWebhook(exotelCallback(['CallSid' => 'x', 'AccountSid' => 'other']))->reason)->toBe('account_mismatch')
        ->and(exotel(['webhook_secret' => ''])->validateWebhook(exotelCallback(['CallSid' => 'x']))->reason)->toBe('not_configured');
});

test('the optional IP allow-list is enforced', function () {
    $provider = exotel(['webhook_allowed_ips' => ['52.0.0.0/8']]);

    expect($provider->validateWebhook(exotelCallback(['CallSid' => 'x'], ip: '52.1.2.3'))->valid)->toBeTrue()
        ->and($provider->validateWebhook(exotelCallback(['CallSid' => 'x'], ip: '10.0.0.1'))->reason)->toBe('ip_not_allowed');
});

test('a terminal status callback is parsed and sanitized', function () {
    $event = exotel()->parseWebhook(exotelCallback([
        'CallSid' => 'EXO-1', 'EventType' => 'terminal', 'Status' => 'completed', 'Direction' => 'outbound-api',
        'CustomField' => 'crm-ref-1', 'ConversationDuration' => 95,
        'StartTime' => '2026-09-24 10:00:00', 'EndTime' => '2026-09-24 10:02:00',
        'RecordingUrl' => 'https://recordings.exotel.com/a.mp3', 'api_token' => 'leak',
        'Legs' => [['Status' => 'completed'], ['Status' => 'completed', 'RingingDuration' => 7]],
    ]), 'status');

    expect($event->status)->toBe(CallStatus::Completed)
        ->and($event->direction)->toBe(CallDirection::Outbound)
        ->and($event->reference)->toBe('crm-ref-1')
        ->and($event->talkSeconds)->toBe(95)
        ->and($event->ringSeconds)->toBe(7)
        ->and($event->totalSeconds)->toBe(120)
        ->and($event->recordingReference)->toBe('https://recordings.exotel.com/a.mp3')
        ->and($event->startedAt->format('H:i'))->toBe('04:30')
        ->and($event->payload)->not->toHaveKey('RecordingUrl')
        ->and($event->payload['api_token'])->toBe('[REDACTED]');
});

test('a completed click-to-call without conversation maps to the failing leg', function () {
    $event = exotel()->parseWebhook(exotelCallback([
        'CallSid' => 'EXO-2', 'Status' => 'completed', 'ConversationDuration' => 0,
        'Legs' => [['Status' => 'completed'], ['Status' => 'busy']],
    ]), 'status');

    expect($event->status)->toBe(CallStatus::Busy)->and($event->failureCode)->toBe('busy');

    $agentMissed = exotel()->parseWebhook(exotelCallback([
        'CallSid' => 'EXO-3', 'Status' => 'completed', 'ConversationDuration' => 0,
        'Legs' => [['Status' => 'no-answer'], []],
    ]), 'status');

    expect($agentMissed->status)->toBe(CallStatus::NoAnswer)->and($agentMissed->failureCode)->toBe('agent_unanswered');
});

test('passthru callbacks produce incoming and missed events', function () {
    $incoming = exotel()->parseWebhook(exotelCallback(['CallSid' => 'IN-1', 'CallFrom' => '09876543210', 'CallTo' => '08000000001']), 'passthru');
    expect($incoming->type)->toBe('incoming')->and($incoming->direction)->toBe(CallDirection::Inbound)->and($incoming->fromNumber)->toBe('09876543210');

    $missed = exotel()->parseWebhook(exotelCallback(['CallSid' => 'IN-1', 'DialCallStatus' => 'no-answer', 'DialWhomNumber' => '09000000001']), 'passthru');
    expect($missed->status)->toBe(CallStatus::Missed)->and($missed->agentNumber)->toBe('09000000001');
});

test('callbacks without a call id are ignored', function () {
    expect(exotel()->parseWebhook(exotelCallback(['Status' => 'completed']), 'status'))->toBeNull();
});

test('recordings are fetched only from allow-listed HTTPS hosts', function () {
    $provider = exotel();

    expect($provider->acceptsRecordingReference('https://recordings.exotel.com/a.mp3'))->toBeTrue()
        ->and($provider->acceptsRecordingReference('https://bucket.s3.amazonaws.com/a.mp3'))->toBeTrue()
        ->and($provider->acceptsRecordingReference('http://recordings.exotel.com/a.mp3'))->toBeFalse()
        ->and($provider->acceptsRecordingReference('https://evil.com/a.mp3'))->toBeFalse()
        ->and($provider->acceptsRecordingReference('https://exotel.com.evil.com/a.mp3'))->toBeFalse()
        ->and($provider->acceptsRecordingReference('https://user:pw@recordings.exotel.com/a.mp3'))->toBeFalse()
        ->and($provider->acceptsRecordingReference('file:///etc/passwd'))->toBeFalse();

    Http::fake();
    expect(fn () => $provider->getRecording('https://169.254.169.254/latest'))->toThrow(TelephonyException::class);
    Http::assertNothingSent();
});

test('recording downloads forward range requests and authenticate only to Exotel hosts', function () {
    Http::fake([
        'recordings.exotel.com/*' => Http::response('abc', 206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-2/10', 'Content-Length' => '3']),
        'bucket.s3.amazonaws.com/*' => Http::response('xyz', 200, ['Content-Type' => 'audio/wav']),
    ]);

    $stream = exotel()->getRecording('https://recordings.exotel.com/a.mp3', 'bytes=0-2');
    expect($stream->status)->toBe(206)->and($stream->contentRange)->toBe('bytes 0-2/10');

    exotel()->getRecording('https://bucket.s3.amazonaws.com/a.wav');

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'exotel.com') && $r->hasHeader('Range', 'bytes=0-2') && $r->hasHeader('Authorization'));
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'amazonaws.com') && ! $r->hasHeader('Authorization'));
});

test('browser sessions require the SDK configuration and a per-agent identity', function () {
    $agent = new TelephonyUser;
    $agent->provider_user_id = 'exo-user-7';

    expect(fn () => exotel()->createWebRtcSession($agent))->toThrow(TelephonyException::class);

    $session = exotel(['webrtc_access_token' => 'sdk-token', 'webrtc_sdk_url' => 'https://sdk.exotel.test/crm.js'])->createWebRtcSession($agent);
    expect($session->toArray()['credentials']['user_id'])->toBe('exo-user-7');

    $agent->provider_user_id = null;
    expect(fn () => exotel(['webrtc_access_token' => 'sdk-token', 'webrtc_sdk_url' => 'https://sdk.exotel.test/crm.js'])->createWebRtcSession($agent))->toThrow(TelephonyException::class);
});

test('callback URLs carry the secret token only for the provider', function () {
    expect(exotel()->callbackUrl('status'))->toContain('/webhooks/telephony/exotel/status')->toContain('token=hook-secret');
});
