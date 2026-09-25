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
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Local-development / automated-test simulator. Never contacts a network.
 * TelephonyManager refuses to resolve it outside the allowed environments.
 *
 * Callbacks use a simple JSON shape:
 *   { call_id, status, event_id?, reference?, direction?, from?, to?, agent_number?,
 *     talk_seconds?, ring_seconds?, recording_url?, occurred_at? }
 * status: ringing | answered | completed | busy | no_answer | failed | cancelled | missed | incoming
 */
class FakeTelephonyProvider implements TelephonyProviderInterface
{
    /** @var array<OutboundCallRequest> */
    public array $outbound = [];

    /** @var array<string, ProviderCallEvent> */
    public array $calls = [];

    public ?TelephonyException $failure = null;

    public bool $webRtc = true;

    public function __construct(private readonly string $webhookSecret) {}

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsWebRtc(): bool
    {
        return $this->webRtc;
    }

    public function failWith(?TelephonyException $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function initiateOutboundCall(OutboundCallRequest $request): ProviderCallResult
    {
        if ($this->failure) {
            throw $this->failure;
        }

        $this->outbound[] = $request;

        return new ProviderCallResult('FAKE-'.Str::upper(Str::random(16)), CallStatus::Queued, 'queued');
    }

    public function getCall(string $providerCallId): ?ProviderCallEvent
    {
        if ($this->failure) {
            throw $this->failure;
        }

        return $this->calls[$providerCallId] ?? null;
    }

    public function getCallStatus(string $providerCallId): ?CallStatus
    {
        return $this->getCall($providerCallId)?->status;
    }

    public function createWebRtcSession(TelephonyUser $agent): WebRtcSession
    {
        if (! $this->webRtc) {
            throw TelephonyException::notConfigured('Browser calling is not configured.');
        }

        return new WebRtcSession('fake', null, [
            'session_token' => 'fake-session-'.Str::random(24),
            'user_id' => $agent->provider_user_id ?: 'fake-agent-'.$agent->user_id,
        ]);
    }

    public function acceptsRecordingReference(string $reference): bool
    {
        return str_starts_with($reference, 'fake://recording/');
    }

    public function getRecording(string $reference, ?string $range = null): RecordingStream
    {
        if (! $this->acceptsRecordingReference($reference)) {
            throw new TelephonyException(TelephonyException::REJECTED, 'Recording unavailable.');
        }
        if ($this->failure) {
            throw $this->failure;
        }

        $audio = self::silentWav(2);
        $total = strlen($audio);

        if ($range !== null && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
            $start = $m[1] === '' ? max(0, $total - (int) $m[2]) : (int) $m[1];
            $end = $m[1] === '' || $m[2] === '' ? $total - 1 : min((int) $m[2], $total - 1);
            $chunk = substr($audio, $start, $end - $start + 1);

            return new RecordingStream(Utils::streamFor($chunk), 206, 'audio/wav', strlen($chunk), "bytes {$start}-{$end}/{$total}");
        }

        return new RecordingStream(Utils::streamFor($audio), 200, 'audio/wav', $total);
    }

    public function callbackUrl(string $endpoint): string
    {
        return route('webhooks.telephony.'.$endpoint, ['provider' => $this->name(), 'token' => $this->webhookSecret]);
    }

    public function validateWebhook(Request $request): WebhookValidation
    {
        $token = $request->query('token');

        return is_string($token) && $token !== '' && hash_equals($this->webhookSecret, $token)
            ? WebhookValidation::ok()
            : WebhookValidation::reject('invalid_token');
    }

    public function parseWebhook(Request $request, string $endpoint): ?ProviderCallEvent
    {
        $data = $request->isJson() ? ($request->json()->all() ?: []) : $request->input();

        return is_array($data) ? self::eventFromArray($data) : null;
    }

    public static function eventFromArray(array $data): ?ProviderCallEvent
    {
        $sid = isset($data['call_id']) && is_scalar($data['call_id']) ? (string) $data['call_id'] : '';
        $raw = strtolower((string) ($data['status'] ?? ''));
        if ($sid === '' || $raw === '') {
            return null;
        }

        $incoming = $raw === 'incoming';
        $status = $incoming ? CallStatus::Ringing : CallStatus::tryFrom($raw);
        if ($status === null) {
            return null;
        }

        $direction = isset($data['direction']) ? CallDirection::tryFrom((string) $data['direction']) : null;
        $occurred = isset($data['occurred_at']) ? CarbonImmutable::parse((string) $data['occurred_at'])->utc() : CarbonImmutable::now();
        $talk = isset($data['talk_seconds']) ? max(0, (int) $data['talk_seconds']) : null;
        $terminal = $status->isTerminal();

        return new ProviderCallEvent(
            type: $incoming ? ProviderCallEvent::INCOMING : ProviderCallEvent::STATUS,
            providerCallId: $sid,
            status: $status,
            providerStatus: $raw,
            direction: $incoming ? CallDirection::Inbound : $direction,
            fromNumber: isset($data['from']) ? (string) $data['from'] : null,
            toNumber: isset($data['to']) ? (string) $data['to'] : null,
            virtualNumber: isset($data['virtual_number']) ? (string) $data['virtual_number'] : null,
            agentNumber: isset($data['agent_number']) ? (string) $data['agent_number'] : null,
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            startedAt: $incoming ? $occurred : null,
            answeredAt: $status === CallStatus::Answered ? $occurred : ($status === CallStatus::Completed && $talk ? $occurred->subSeconds($talk) : null),
            endedAt: $terminal ? $occurred : null,
            ringSeconds: isset($data['ring_seconds']) ? (int) $data['ring_seconds'] : null,
            talkSeconds: $terminal ? ($talk ?? 0) : null,
            totalSeconds: $terminal ? ($talk ?? 0) + (int) ($data['ring_seconds'] ?? 0) : null,
            recordingReference: isset($data['recording_url']) ? (string) $data['recording_url'] : null,
            failureCode: $terminal && ! $status->isConnected() ? $status->value : null,
            providerEventId: isset($data['event_id']) ? (string) $data['event_id'] : null,
            occurredAt: $occurred,
            payload: SecretRedactor::array(array_diff_key($data, ['recording_url' => 1, 'token' => 1])),
        );
    }

    public function getNumbers(): array
    {
        return [new ProviderNumber('fake-1', '+918000000001', 'Fake Sales Line')];
    }

    public function healthCheck(): ProviderHealth
    {
        return new ProviderHealth(true, 'connected', 'Local simulator (no real calls are placed).');
    }

    /** A tiny valid 8 kHz mono PCM WAV of silence. */
    public static function silentWav(int $seconds): string
    {
        $data = str_repeat("\x00", 8000 * $seconds);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8).'data'.pack('V', strlen($data)).$data;
    }
}
