<?php

namespace App\Services\Telephony\Providers;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\TelephonyUser;
use App\Services\Telephony\Data\OutboundCallRequest;
use App\Services\Telephony\Data\ProviderCallEvent;
use App\Services\Telephony\Data\ProviderCallResult;
use App\Services\Telephony\Data\ProviderHealth;
use App\Services\Telephony\Data\ProviderNumber;
use App\Services\Telephony\Data\RecordingStream;
use App\Services\Telephony\Data\WebhookValidation;
use App\Services\Telephony\Data\WebRtcSession;
use App\Services\Telephony\TelephonyException;
use App\Support\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Exotel adapter (verified against developer.exotel.com, 2026-09-24).
 *
 *  - Click-to-call: POST https://{subdomain}/v1/Accounts/{sid}/Calls/connect.json
 *    (From = agent phone, called first; To = customer; CallerId = ExoPhone;
 *    CustomField = CRM call reference; StatusCallback for "answered" + "terminal").
 *  - Call details:  GET  /v1/Accounts/{sid}/Calls/{CallSid}.json?details=true
 *  - ExoPhones:     GET  /v2_beta/Accounts/{sid}/IncomingPhoneNumbers
 *  - Account:       GET  /v1/Accounts/{sid}.json (health check)
 *  - Incoming:      Passthru applet (GET) at the start of the call flow and
 *                   after the Connect applet (DialCallStatus/DialWhomNumber).
 *  - Browser:       Exotel CRM Web SDK (IP-PSTN intermix). The SDK needs an
 *                   access token plus the agent's Exotel app user id.
 *
 * Authentication is HTTP Basic (API key : API token) in the Authorization
 * header — never in the URL. Exotel does NOT sign voice callbacks, so
 * callbacks are authenticated with a secret token in the callback URL
 * (constant-time compare), an optional source-IP allow-list and an
 * AccountSid match; status callbacks are only accepted for known calls.
 */
class ExotelTelephonyProvider implements TelephonyProviderInterface
{
    public function __construct(private readonly array $config, private readonly array $http) {}

    public function name(): string
    {
        return 'exotel';
    }

    public function isConfigured(): bool
    {
        return filled($this->config['account_sid'] ?? null)
            && filled($this->config['api_key'] ?? null)
            && filled($this->config['api_token'] ?? null)
            && filled($this->config['subdomain'] ?? null)
            && filled($this->config['webhook_secret'] ?? null);
    }

    public function supportsWebRtc(): bool
    {
        return filled($this->config['webrtc_access_token'] ?? null) && filled($this->config['webrtc_sdk_url'] ?? null);
    }

    public function initiateOutboundCall(OutboundCallRequest $request): ProviderCallResult
    {
        $this->assertConfigured();

        $params = [
            'From' => '+'.$request->agentNumber,
            'To' => '+'.$request->customerNumber,
            'CallerId' => $request->callerId,
            'CallType' => 'trans',
            'Record' => $request->record ? 'true' : 'false',
            'TimeLimit' => (int) ($this->config['time_limit'] ?? 3600),
            'TimeOut' => (int) ($this->config['ring_timeout'] ?? 30),
            'CustomField' => $request->reference,
            'StatusCallback' => $request->statusCallbackUrl,
            'StatusCallbackEvents[0]' => 'terminal',
            'StatusCallbackEvents[1]' => 'answered',
            'StatusCallbackContentType' => 'application/json',
        ];
        if ($request->record) {
            $params['RecordingChannels'] = 'dual';
        }

        $response = $this->send(fn (PendingRequest $http) => $http->asForm()->post($this->url('/Calls/connect.json'), array_filter($params, fn ($v) => $v !== null)), 'connect');

        $call = $response->json('Call') ?? [];
        $sid = (string) ($call['Sid'] ?? '');
        if ($sid === '') {
            throw TelephonyException::unavailable('exotel connect: response without Call.Sid');
        }

        $providerStatus = strtolower((string) ($call['Status'] ?? 'queued'));

        return new ProviderCallResult($sid, $this->mapProgress($providerStatus) ?? CallStatus::Queued, $providerStatus);
    }

    public function getCall(string $providerCallId): ?ProviderCallEvent
    {
        $this->assertConfigured();

        $response = $this->send(
            fn (PendingRequest $http) => $http->get($this->url('/Calls/'.rawurlencode($providerCallId).'.json'), ['details' => 'true']),
            'call-details',
            allowNotFound: true,
        );

        if ($response === null) {
            return null;
        }

        $call = $response->json('Call');

        return is_array($call) ? $this->fromCallResource($call) : null;
    }

    public function getCallStatus(string $providerCallId): ?CallStatus
    {
        return $this->getCall($providerCallId)?->status;
    }

    public function createWebRtcSession(TelephonyUser $agent): WebRtcSession
    {
        if (! $this->supportsWebRtc()) {
            throw TelephonyException::notConfigured('Browser calling is not configured.');
        }
        if (blank($agent->provider_user_id)) {
            throw TelephonyException::notConfigured('Your browser calling account is not set up yet. Please contact your administrator.');
        }

        return new WebRtcSession('exotel', $this->config['webrtc_sdk_url'], [
            'access_token' => $this->config['webrtc_access_token'],
            'user_id' => $agent->provider_user_id,
        ]);
    }

    public function acceptsRecordingReference(string $reference): bool
    {
        $parts = parse_url($reference);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        foreach ($this->config['recording_hosts'] ?? [] as $allowed) {
            $allowed = strtolower($allowed);
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    public function getRecording(string $reference, ?string $range = null): RecordingStream
    {
        if (! $this->acceptsRecordingReference($reference)) {
            throw new TelephonyException(TelephonyException::REJECTED, 'Recording unavailable.', 'exotel recording: host not allowed');
        }

        $host = strtolower((string) parse_url($reference, PHP_URL_HOST));
        $isExotelHost = str_ends_with($host, 'exotel.com') || str_ends_with($host, 'exotel.in');

        try {
            $http = Http::connectTimeout($this->http['connect_timeout'] ?? 5)
                ->timeout($this->http['recording_timeout'] ?? 60)
                ->withOptions(['stream' => true, 'allow_redirects' => false]);
            if ($isExotelHost) {
                $http = $http->withBasicAuth((string) $this->config['api_key'], (string) $this->config['api_token']);
            }
            if ($range !== null) {
                $http = $http->withHeaders(['Range' => $range]);
            }
            $response = $http->get($reference);
        } catch (ConnectionException $e) {
            throw TelephonyException::unavailable('exotel recording: connection failed');
        }

        if ($response->status() === 404 || $response->status() === 403) {
            throw new TelephonyException(TelephonyException::NOT_FOUND, 'Recording unavailable.', 'exotel recording: HTTP '.$response->status());
        }
        if (! in_array($response->status(), [200, 206], true)) {
            throw TelephonyException::unavailable('exotel recording: HTTP '.$response->status());
        }

        $psr = $response->toPsrResponse();
        $length = $psr->getHeaderLine('Content-Length');

        return new RecordingStream(
            $psr->getBody(),
            $response->status(),
            $this->audioMime($psr->getHeaderLine('Content-Type'), $reference),
            $length !== '' ? (int) $length : null,
            $psr->getHeaderLine('Content-Range') ?: null,
        );
    }

    public function callbackUrl(string $endpoint): string
    {
        return route('webhooks.telephony.'.$endpoint, ['provider' => $this->name(), 'token' => (string) ($this->config['webhook_secret'] ?? '')]);
    }

    public function validateWebhook(Request $request): WebhookValidation
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        if ($secret === '') {
            return WebhookValidation::reject('not_configured');
        }

        $allowedIps = $this->config['webhook_allowed_ips'] ?? [];
        if ($allowedIps !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowedIps)) {
            return WebhookValidation::reject('ip_not_allowed');
        }

        $token = $request->query('token');
        if (! is_string($token) || $token === '' || ! hash_equals($secret, $token)) {
            return WebhookValidation::reject('invalid_token');
        }

        $accountSid = $request->input('AccountSid');
        if (is_string($accountSid) && $accountSid !== '' && ! hash_equals((string) $this->config['account_sid'], $accountSid)) {
            return WebhookValidation::reject('account_mismatch');
        }

        return WebhookValidation::ok();
    }

    public function parseWebhook(Request $request, string $endpoint): ?ProviderCallEvent
    {
        $data = $request->isJson() ? ($request->json()->all() ?: []) : $request->input();
        $data = is_array($data) ? $data : [];

        return $endpoint === 'passthru' ? $this->fromPassthru($data) : $this->fromStatusCallback($data);
    }

    public function getNumbers(): array
    {
        $this->assertConfigured();

        $response = $this->send(
            fn (PendingRequest $http) => $http->get('https://'.$this->config['subdomain'].'/v2_beta/Accounts/'.rawurlencode((string) $this->config['account_sid']).'/IncomingPhoneNumbers'),
            'numbers',
        );

        $rows = $response->json('incoming_phone_numbers') ?? $response->json('IncomingPhoneNumbers') ?? [];

        return collect(is_array($rows) ? $rows : [])
            ->map(function ($row) {
                $row = $row['IncomingPhoneNumber'] ?? $row;
                $number = (string) ($row['phone_number'] ?? $row['PhoneNumber'] ?? '');

                return $number === '' ? null : new ProviderNumber(
                    isset($row['sid']) || isset($row['Sid']) ? (string) ($row['sid'] ?? $row['Sid']) : null,
                    $number,
                    isset($row['friendly_name']) ? (string) $row['friendly_name'] : null,
                    strtolower((string) ($row['number_type'] ?? 'virtual')) ?: 'virtual',
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    public function healthCheck(): ProviderHealth
    {
        if (! $this->isConfigured()) {
            return new ProviderHealth(false, 'not_configured', 'Exotel credentials are incomplete.');
        }

        try {
            $this->send(fn (PendingRequest $http) => $http->get('https://'.$this->config['subdomain'].'/v1/Accounts/'.rawurlencode((string) $this->config['account_sid']).'.json'), 'health');
        } catch (TelephonyException $e) {
            return new ProviderHealth(false, $e->category === TelephonyException::NOT_CONFIGURED ? 'auth_failed' : 'error', $e->getMessage());
        }

        return new ProviderHealth(true, 'connected', 'Exotel account reachable.');
    }

    // ---------------------------------------------------------------------

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw TelephonyException::notConfigured();
        }
    }

    private function url(string $path): string
    {
        return 'https://'.$this->config['subdomain'].'/v1/Accounts/'.rawurlencode((string) $this->config['account_sid']).$path;
    }

    /** Sends an authenticated request and translates failures. Returns null for 404 when allowed. */
    private function send(callable $request, string $operation, bool $allowNotFound = false): ?Response
    {
        $http = Http::withBasicAuth((string) $this->config['api_key'], (string) $this->config['api_token'])
            ->acceptJson()
            ->connectTimeout($this->http['connect_timeout'] ?? 5)
            ->timeout($this->http['timeout'] ?? 15);

        try {
            /** @var Response $response */
            $response = $request($http);
        } catch (ConnectionException) {
            $this->logFailure($operation, 'connection failed');
            throw TelephonyException::unavailable("exotel {$operation}: connection failed");
        } catch (Throwable $e) {
            $this->logFailure($operation, $e::class);
            throw TelephonyException::unavailable("exotel {$operation}: ".$e::class);
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();
        if ($status === 404 && $allowNotFound) {
            return null;
        }

        $message = preg_replace('/\+?\d[\d\s\-]{6,}\d/', '[number]', SecretRedactor::text((string) ($response->json('RestException.Message') ?? ''))) ?? '';
        $detail = "exotel {$operation}: HTTP {$status} ".mb_substr($message, 0, 200);
        $this->logFailure($operation, "HTTP {$status}");

        throw match (true) {
            $status === 401 || $status === 403 => TelephonyException::notConfigured('The calling provider rejected the configured credentials. Please contact your administrator.'),
            $status === 429 || $status >= 500 => TelephonyException::unavailable($detail),
            default => TelephonyException::rejected('The call could not be placed. Please check the number and try again.', $detail),
        };
    }

    private function logFailure(string $operation, string $reason): void
    {
        Log::warning('Telephony provider request failed', ['provider' => 'exotel', 'operation' => $operation, 'reason' => $reason]);
    }

    private function fromStatusCallback(array $data): ?ProviderCallEvent
    {
        $sid = $this->str($data, 'CallSid');
        if ($sid === null) {
            return null;
        }

        $providerStatus = strtolower((string) ($this->str($data, 'Status') ?? ''));
        $eventType = strtolower((string) ($this->str($data, 'EventType') ?? 'terminal'));
        $direction = $this->direction($this->str($data, 'Direction'));
        $legs = is_array($data['Legs'] ?? null) ? array_values($data['Legs']) : [];
        $talk = $this->int($data, 'ConversationDuration');

        $start = $this->time($this->str($data, 'StartTime'));
        $end = $this->time($this->str($data, 'EndTime'));

        if ($eventType === 'answered') {
            $status = CallStatus::Answered;
            $providerStatus = 'answered';
        } else {
            [$status, $failure] = $this->terminalStatus($providerStatus, $talk, $legs, $direction);
        }

        $isTerminal = isset($status) && $status->isTerminal();
        $customerLeg = $legs[1] ?? null;

        return new ProviderCallEvent(
            type: ProviderCallEvent::STATUS,
            providerCallId: $sid,
            status: $status,
            providerStatus: $providerStatus ?: null,
            direction: $direction,
            fromNumber: $this->str($data, 'From'),
            toNumber: $this->str($data, 'To'),
            reference: $this->str($data, 'CustomField'),
            startedAt: $start,
            answeredAt: $isTerminal && $talk > 0 && $end ? $end->subSeconds($talk) : ($eventType === 'answered' ? $this->time($this->str($data, 'DateUpdated')) : null),
            endedAt: $isTerminal ? $end : null,
            ringSeconds: is_array($customerLeg) && isset($customerLeg['RingingDuration']) ? (int) $customerLeg['RingingDuration'] : null,
            talkSeconds: $isTerminal ? $talk : null,
            totalSeconds: $isTerminal && $start && $end ? max(0, (int) $start->diffInSeconds($end)) : null,
            recordingReference: $this->str($data, 'RecordingUrl'),
            failureCode: $failure ?? null,
            providerEventId: $eventType,
            occurredAt: $this->time($this->str($data, 'DateUpdated')),
            payload: $this->sanitize($data),
        );
    }

    private function fromPassthru(array $data): ?ProviderCallEvent
    {
        $sid = $this->str($data, 'CallSid');
        if ($sid === null) {
            return null;
        }

        $dialStatus = $this->str($data, 'DialCallStatus');
        $from = $this->str($data, 'CallFrom') ?? $this->str($data, 'From');
        $virtual = $this->str($data, 'CallTo') ?? $this->str($data, 'To');
        $occurred = $this->time($this->str($data, 'CurrentTime'));

        if ($dialStatus === null) {
            return new ProviderCallEvent(
                type: ProviderCallEvent::INCOMING,
                providerCallId: $sid,
                status: CallStatus::Ringing,
                providerStatus: 'incoming',
                direction: CallDirection::Inbound,
                fromNumber: $from,
                toNumber: $virtual,
                virtualNumber: $virtual,
                startedAt: $occurred,
                providerEventId: 'incoming',
                occurredAt: $occurred,
                payload: $this->sanitize($data),
            );
        }

        $dialStatus = strtolower($dialStatus);
        $talk = $this->int($data, 'DialCallDuration');
        $connected = $dialStatus === 'completed' && $talk > 0;
        $status = $connected ? CallStatus::Completed : CallStatus::Missed;

        return new ProviderCallEvent(
            type: ProviderCallEvent::STATUS,
            providerCallId: $sid,
            status: $status,
            providerStatus: $dialStatus,
            direction: CallDirection::Inbound,
            fromNumber: $from,
            toNumber: $virtual,
            virtualNumber: $virtual,
            agentNumber: $this->str($data, 'DialWhomNumber'),
            answeredAt: $connected && $occurred ? $occurred->subSeconds($talk) : null,
            endedAt: $occurred,
            talkSeconds: $talk,
            recordingReference: $connected ? $this->str($data, 'RecordingUrl') : null,
            failureCode: $connected ? null : $dialStatus,
            providerEventId: 'dial:'.$dialStatus,
            occurredAt: $occurred,
            payload: $this->sanitize($data),
        );
    }

    private function fromCallResource(array $call): ?ProviderCallEvent
    {
        $sid = (string) ($call['Sid'] ?? '');
        if ($sid === '') {
            return null;
        }

        $providerStatus = strtolower((string) ($call['Status'] ?? ''));
        $details = is_array($call['Details'] ?? null) ? $call['Details'] : [];
        $direction = $this->direction($call['Direction'] ?? null);
        $talk = isset($details['ConversationDuration']) ? (int) $details['ConversationDuration'] : null;
        $legs = is_array($details['Legs'] ?? null) ? array_values($details['Legs']) : [];

        $status = $this->mapProgress($providerStatus);
        $failure = null;
        if ($status === null || $status->isTerminal()) {
            [$status, $failure] = $this->terminalStatus($providerStatus, $talk ?? (int) ($call['Duration'] ?? 0), $legs, $direction);
        }

        $start = $this->time($call['StartTime'] ?? null);
        $end = $this->time($call['EndTime'] ?? null);

        return new ProviderCallEvent(
            type: ProviderCallEvent::DETAILS,
            providerCallId: $sid,
            status: $status,
            providerStatus: $providerStatus ?: null,
            direction: $direction,
            fromNumber: isset($call['From']) ? (string) $call['From'] : null,
            toNumber: isset($call['To']) ? (string) $call['To'] : null,
            startedAt: $start,
            answeredAt: $status?->isConnected() && $talk && $end ? $end->subSeconds($talk) : null,
            endedAt: $status?->isTerminal() ? $end : null,
            talkSeconds: $status?->isTerminal() ? ($talk ?? ($status === CallStatus::Completed ? (int) ($call['Duration'] ?? 0) : 0)) : null,
            totalSeconds: $status?->isTerminal() && isset($call['Duration']) ? (int) $call['Duration'] : null,
            recordingReference: isset($call['RecordingUrl']) && $call['RecordingUrl'] !== '' ? (string) $call['RecordingUrl'] : null,
            failureCode: $failure,
            providerEventId: 'details:'.$providerStatus,
            occurredAt: $this->time($call['DateUpdated'] ?? null),
            payload: $this->sanitize($call),
        );
    }

    /** Non-terminal provider states. */
    private function mapProgress(string $status): ?CallStatus
    {
        return match ($status) {
            'queued' => CallStatus::Queued,
            'ringing' => CallStatus::Ringing,
            'in-progress' => CallStatus::Answered,
            default => null,
        };
    }

    /**
     * Terminal translation. For click-to-call an overall "completed" with no
     * conversation means one of the legs never connected.
     *
     * @return array{0: CallStatus, 1: ?string}
     */
    private function terminalStatus(string $status, ?int $talk, array $legs, ?CallDirection $direction): array
    {
        $inbound = $direction === CallDirection::Inbound;
        $unconnected = fn (CallStatus $s, ?string $code) => [$inbound ? CallStatus::Missed : $s, $code];

        return match ($status) {
            'completed' => ($talk ?? 0) > 0
                ? [CallStatus::Completed, null]
                : $this->fromLegs($legs, $unconnected),
            'busy' => $unconnected(CallStatus::Busy, 'busy'),
            'no-answer' => $unconnected(CallStatus::NoAnswer, 'no_answer'),
            'canceled', 'cancelled' => $unconnected(CallStatus::Cancelled, 'cancelled'),
            'failed' => $unconnected(CallStatus::Failed, 'failed'),
            'in-progress' => [CallStatus::Answered, null],
            default => $unconnected(CallStatus::Failed, $status !== '' ? mb_substr($status, 0, 40) : 'unknown'),
        };
    }

    private function fromLegs(array $legs, callable $unconnected): array
    {
        $agentLeg = strtolower((string) ($legs[0]['Status'] ?? ''));
        $customerLeg = strtolower((string) ($legs[1]['Status'] ?? ''));

        if ($agentLeg !== '' && $agentLeg !== 'completed') {
            return $unconnected(CallStatus::NoAnswer, 'agent_unanswered');
        }

        return match ($customerLeg) {
            'busy' => $unconnected(CallStatus::Busy, 'busy'),
            'failed' => $unconnected(CallStatus::Failed, 'failed'),
            'canceled', 'cancelled' => $unconnected(CallStatus::Cancelled, 'cancelled'),
            default => $unconnected(CallStatus::NoAnswer, 'no_answer'),
        };
    }

    private function direction(mixed $value): ?CallDirection
    {
        $value = strtolower((string) $value);

        return match (true) {
            $value === 'inbound' || $value === 'incoming' => CallDirection::Inbound,
            str_starts_with($value, 'outbound') => CallDirection::Outbound,
            default => null,
        };
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $this->config['timezone'] ?? 'Asia/Kolkata')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' || strtolower($value) === 'null' ? null : mb_substr($value, 0, 500);
    }

    private function int(array $data, string $key): int
    {
        return max(0, (int) ($data[$key] ?? 0));
    }

    private function audioMime(string $header, string $reference): string
    {
        $header = strtolower(trim(explode(';', $header)[0]));
        if (str_starts_with($header, 'audio/')) {
            return $header;
        }

        return str_ends_with(strtolower((string) parse_url($reference, PHP_URL_PATH)), '.wav') ? 'audio/wav' : 'audio/mpeg';
    }

    /** Credentials redacted; recording URLs are stored separately (encrypted) and dropped here. */
    private function sanitize(array $data): array
    {
        unset($data['RecordingUrl'], $data['PreSignedRecordingUrl'], $data['token']);

        return SecretRedactor::array($data);
    }
}
