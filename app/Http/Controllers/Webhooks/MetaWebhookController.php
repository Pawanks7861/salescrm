<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Meta\MetaWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public Meta webhook endpoint. Trust comes ONLY from the verify token (GET)
 * and the X-Hub-Signature-256 HMAC of the raw body (POST) — never from a
 * session, cookie or CSRF token. Plain-text responses; no Inertia/error pages.
 */
class MetaWebhookController extends Controller
{
    public function __construct(private readonly MetaWebhookService $webhooks) {}

    public function verify(Request $request): Response
    {
        // PHP turns "hub.mode" into "hub_mode" in the query bag.
        $challenge = $this->webhooks->verifyChallenge(
            $request->query('hub_mode'),
            $request->query('hub_verify_token'),
            $request->query('hub_challenge'),
        );

        if ($challenge === null) {
            $this->webhooks->recordRejection('invalid_verification', $request->ip());

            return $this->plain('Forbidden', 403);
        }

        return $this->plain($challenge, 200);
    }

    public function receive(Request $request): Response
    {
        $limiterKey = 'meta-webhook-invalid:'.$request->ip();
        $limit = (int) config('meta.invalid_signature_limit', 20);

        if (RateLimiter::tooManyAttempts($limiterKey, $limit)) {
            return $this->plain('Too Many Requests', 429);
        }

        // The exact bytes Meta signed. Never decode/re-encode before hashing.
        $raw = $request->getContent();

        if (strlen($raw) > (int) config('meta.max_payload_bytes', 2 * 1024 * 1024)) {
            $this->webhooks->recordRejection('payload_too_large', $request->ip());

            return $this->plain('Payload Too Large', 413);
        }

        if (! $this->webhooks->verifySignature($raw, $request->header('X-Hub-Signature-256'))) {
            RateLimiter::hit($limiterKey, 60);
            $this->webhooks->recordRejection($request->hasHeader('X-Hub-Signature-256') ? 'invalid_signature' : 'missing_signature', $request->ip());

            return $this->plain('Forbidden', 403);
        }

        $payload = json_decode($raw, true, 64);
        if (! is_array($payload)) {
            $this->webhooks->recordRejection('malformed_json', $request->ip());

            return $this->plain('Bad Request', 400);
        }

        $this->webhooks->accept($payload);

        return $this->plain('EVENT_RECEIVED', 200);
    }

    private function plain(string $body, int $status): Response
    {
        return response($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
