<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Telephony\CallWebhookService;
use App\Services\Telephony\TelephonyManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Public telephony callbacks. Trust comes only from the provider adapter's
 * validateWebhook() — never from a session or cookie. Plain-text responses.
 *
 *  status   — call progress / terminal callbacks. A processing failure returns
 *             500 so the provider retries (the event ledger lets it re-run).
 *  passthru — incoming call flow. Always 200 once authenticated so the
 *             caller's call flow continues even if CRM processing failed.
 */
class TelephonyWebhookController extends Controller
{
    public function __construct(
        private readonly TelephonyManager $telephony,
        private readonly CallWebhookService $webhooks,
        private readonly AuditService $audit,
    ) {}

    public function status(Request $request, string $provider): Response
    {
        return $this->handle($request, $provider, 'status');
    }

    public function passthru(Request $request, string $provider): Response
    {
        return $this->handle($request, $provider, 'passthru');
    }

    private function handle(Request $request, string $provider, string $endpoint): Response
    {
        $adapter = $this->telephony->provider();
        if ($provider !== $adapter->name()) {
            return $this->plain('Not Found', 404);
        }

        $limiterKey = 'telephony-webhook-invalid:'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, (int) config('telephony.invalid_callback_limit', 20))) {
            return $this->plain('Too Many Requests', 429);
        }

        if (strlen($request->getContent()) > (int) config('telephony.max_payload_bytes', 256 * 1024)) {
            $this->reject('payload_too_large', $request->ip());

            return $this->plain('Payload Too Large', 413);
        }

        $validation = $adapter->validateWebhook($request);
        if (! $validation->valid) {
            RateLimiter::hit($limiterKey, 60);
            $this->reject((string) $validation->reason, $request->ip());

            return $this->plain('Forbidden', 403);
        }

        $event = $adapter->parseWebhook($request, $endpoint);
        if ($event === null) {
            Log::notice('Telephony callback without a usable call id', ['endpoint' => $endpoint]);

            return $this->plain('OK', 200);
        }

        try {
            $result = $this->webhooks->ingest($event, $request->ip());
        } catch (Throwable) {
            return $endpoint === 'passthru' ? $this->plain('OK', 200) : $this->plain('Error', 500);
        }

        return $this->plain($result === CallWebhookService::DUPLICATE ? 'DUPLICATE' : 'OK', 200);
    }

    private function reject(string $reason, ?string $ip): void
    {
        Log::warning('Telephony callback rejected', ['reason' => $reason, 'ip' => $ip]);

        if (Cache::add('telephony-webhook-rejected:'.sha1($reason.'|'.$ip), true, now()->addMinute())) {
            $this->audit->log(AuditAction::CallWebhookRejected, 'calls', null, "Telephony callback rejected: {$reason}", null, ['reason' => $reason, 'ip' => $ip]);
        }
    }

    private function plain(string $body, int $status): Response
    {
        return response($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
