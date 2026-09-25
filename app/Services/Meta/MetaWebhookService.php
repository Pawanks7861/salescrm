<?php

namespace App\Services\Meta;

use App\Enums\AuditAction;
use App\Enums\FacebookEventStatus;
use App\Jobs\ProcessMetaLeadEvent;
use App\Models\FacebookForm;
use App\Models\FacebookPage;
use App\Models\FacebookWebhookEvent;
use App\Services\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Minimal, synchronous webhook handling. Verifies the request, records one
 * event row per leadgen_id and queues processing — no Graph calls, lead
 * creation, duplicate checks or notifications happen before Meta gets its 200.
 */
class MetaWebhookService
{
    public function __construct(
        private readonly MetaIntegrationService $integrations,
        private readonly AuditService $audit,
    ) {}

    /** Returns the challenge to echo, or null when verification fails. */
    public function verifyChallenge(mixed $mode, mixed $token, mixed $challenge): ?string
    {
        $expected = (string) config('meta.webhook_verify_token');

        if ($expected === '' || $mode !== 'subscribe' || ! is_string($token) || ! is_string($challenge)) {
            return null;
        }
        if (! hash_equals($expected, $token)) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_\-.]{1,256}$/', $challenge) === 1 ? $challenge : null;
    }

    /**
     * HMAC-SHA256 of the EXACT raw request bytes with the app secret, compared
     * in constant time. The body is never decoded/re-encoded before hashing.
     */
    public function verifySignature(string $rawBody, ?string $header): bool
    {
        $secret = (string) config('meta.app_secret');

        if ($secret === '' || ! is_string($header) || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $provided = substr($header, 7);
        if (preg_match('/^[a-f0-9]{64}$/i', $provided) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($provided));
    }

    /**
     * Records a rejected delivery with safe metadata only (no body, no
     * signature, no secret). Audited at most once per IP+reason per minute.
     */
    public function recordRejection(string $reason, ?string $ip): void
    {
        Log::warning('Meta webhook rejected', ['reason' => $reason, 'ip' => $ip]);

        if (Cache::add('meta-webhook-rejected:'.sha1($reason.'|'.$ip), true, now()->addMinute())) {
            $this->audit->log(AuditAction::FacebookWebhookRejected, 'integrations', null, "Meta webhook rejected: {$reason}", null, ['reason' => $reason, 'ip' => $ip]);
        }
    }

    /**
     * Handles a verified payload. Every change is isolated: a malformed change
     * is skipped without affecting the others.
     *
     * @return array{accepted: int, duplicates: int, ignored: int, skipped: int}
     */
    public function accept(array $payload): array
    {
        $summary = ['accepted' => 0, 'duplicates' => 0, 'ignored' => 0, 'skipped' => 0];

        if (($payload['object'] ?? null) !== 'page' || ! is_array($payload['entry'] ?? null)) {
            $summary['skipped']++;

            return $summary;
        }

        foreach ($payload['entry'] as $entry) {
            foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
                try {
                    if (! is_array($change) || ($change['field'] ?? null) !== 'leadgen' || ! is_array($change['value'] ?? null)) {
                        $summary['skipped']++;

                        continue;
                    }

                    $value = $change['value'];
                    $value['page_id'] ??= $entry['id'] ?? null;

                    $outcome = $this->register($value, FacebookWebhookEvent::ORIGIN_WEBHOOK)[1];
                    $summary[$outcome]++;
                } catch (\Throwable $e) {
                    $summary['skipped']++;
                    Log::warning('Meta webhook change skipped', ['error' => class_basename($e)]);
                }
            }
        }

        $this->integrations->touchWebhook();

        return $summary;
    }

    /**
     * Idempotent registration keyed by leadgen_id. A redelivery only bumps
     * delivery_count; it is never queued again once processed/ignored.
     *
     * @return array{0: ?FacebookWebhookEvent, 1: string} [event, accepted|duplicates|ignored|skipped]
     */
    public function register(array $value, string $origin, bool $dispatch = true): array
    {
        $leadgenId = (string) ($value['leadgen_id'] ?? '');
        $pageId = (string) ($value['page_id'] ?? '');

        if (preg_match('/^\d{1,64}$/', $leadgenId) !== 1 || preg_match('/^[A-Za-z0-9_]{1,64}$/', $pageId) !== 1) {
            return [null, 'skipped'];
        }

        $existing = FacebookWebhookEvent::query()->where('leadgen_id', $leadgenId)->first();
        if ($existing) {
            return [$this->redelivered($existing), 'duplicates'];
        }

        $formId = preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) ($value['form_id'] ?? '')) === 1 ? (string) $value['form_id'] : null;
        $page = FacebookPage::query()->where('page_id', $pageId)->whereHas('integration')->latest('id')->first();
        $form = $page && $formId ? FacebookForm::query()->where('facebook_page_id', $page->id)->where('form_id', $formId)->first() : null;

        [$status, $reason] = match (true) {
            ! $page => [FacebookEventStatus::Ignored, 'unknown_page'],
            ! $page->receivesLeads() => [FacebookEventStatus::Ignored, 'page_disabled'],
            $form && ! $form->is_enabled => [FacebookEventStatus::Ignored, 'form_disabled'],
            default => [FacebookEventStatus::Queued, null],
        };

        try {
            $event = FacebookWebhookEvent::query()->forceCreate([
                'leadgen_id' => $leadgenId,
                'origin' => $origin,
                'page_id' => $pageId,
                'form_id' => $formId,
                'ad_id' => preg_match('/^\d{1,64}$/', (string) ($value['ad_id'] ?? '')) === 1 ? (string) $value['ad_id'] : null,
                'facebook_page_id' => $page?->id,
                'facebook_form_id' => $form?->id,
                'payload_json' => $this->safePayload($value),
                'meta_created_at' => $this->timestamp($value['created_time'] ?? null),
                'received_at' => now(),
                'last_delivered_at' => now(),
                'processing_status' => $status,
                'error_code' => $reason,
                'processed_at' => $status === FacebookEventStatus::Ignored ? now() : null,
            ]);
        } catch (QueryException) {
            // Concurrent delivery of the same leadgen_id won the insert.
            $existing = FacebookWebhookEvent::query()->where('leadgen_id', $leadgenId)->first();

            return [$existing ? $this->redelivered($existing) : null, 'duplicates'];
        }

        if ($status === FacebookEventStatus::Queued) {
            if ($dispatch) {
                $this->dispatch($event);
            }

            return [$event, 'accepted'];
        }

        return [$event, 'ignored'];
    }

    public function dispatch(FacebookWebhookEvent $event, int $delaySeconds = 0): void
    {
        $job = ProcessMetaLeadEvent::dispatch($event->id)->onQueue(config('meta.queue'));
        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
    }

    private function redelivered(FacebookWebhookEvent $event): FacebookWebhookEvent
    {
        FacebookWebhookEvent::query()->whereKey($event->id)->update([
            'delivery_count' => DB::raw('delivery_count + 1'),
            'last_delivered_at' => now(),
        ]);

        return $event;
    }

    /** Only Meta identifiers and the submission time — no answers/PII. */
    private function safePayload(array $value): array
    {
        return collect($value)
            ->only(['leadgen_id', 'page_id', 'form_id', 'ad_id', 'adgroup_id', 'created_time'])
            ->map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 64) : null)
            ->filter()
            ->all();
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if (is_numeric($value) && (int) $value > 0) {
            return Carbon::createFromTimestamp((int) $value);
        }
        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
