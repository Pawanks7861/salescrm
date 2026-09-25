<?php

namespace App\Services\Meta;

use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Enums\FacebookEventStatus;
use App\Enums\MetaErrorCategory;
use App\Models\FacebookForm;
use App\Models\FacebookIntegration;
use App\Models\FacebookPage;
use App\Models\FacebookWebhookEvent;
use App\Models\Lead;
use App\Models\LeadEnquiry;
use App\Models\User;
use App\Notifications\Leads\FacebookLeadNotification;
use App\Services\AuditService;
use App\Services\Leads\LeadCustomFieldService;
use App\Services\Leads\LeadService;
use App\Services\SettingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE Meta lead pipeline. Webhook jobs, manual sync and local test ingestion
 * all call process(); there is no second implementation.
 *
 *   claim event → (fetch lead from Meta, outside any transaction) → resolve
 *   Page/Form → map fields → campaign upsert → ONE transaction: re-lock the
 *   event, idempotency checks, LeadService::createFromInbound (duplicate
 *   policy, enquiry, custom fields, assignment engine, activity, audit),
 *   mark processed → commit → notify.
 *
 * Idempotency: the event row is unique per leadgen_id and locked inside the
 * transaction; lead_enquiries is unique on (channel, external_id) and
 * leads.facebook_lead_id is unique — so a retried/redelivered lead can never
 * create a second lead, enquiry, assignment or notification.
 */
class MetaLeadIngestionService
{
    private const STALE_PROCESSING_MINUTES = 10;

    public function __construct(
        private readonly MetaLeadService $leads,
        private readonly MetaFormService $forms,
        private readonly MetaFieldMappingService $mapping,
        private readonly MetaCampaignService $campaigns,
        private readonly MetaIntegrationService $integrations,
        private readonly LeadService $leadService,
        private readonly LeadCustomFieldService $customFields,
        private readonly SettingService $settings,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array|null  $lead  already-fetched Meta lead (manual sync / test); fetched when null
     * @return array{status: string, retry_in: ?int, lead_id: ?int}
     */
    public function process(FacebookWebhookEvent $event, ?array $lead = null): array
    {
        if (! $this->claim($event)) {
            return $this->result($event->fresh() ?? $event);
        }
        $event->refresh();

        try {
            $page = $this->page($event);
            if (! $page) {
                return $this->finish($event, FacebookEventStatus::Ignored, 'unknown_page');
            }
            if (! $page->receivesLeads()) {
                return $this->finish($event, FacebookEventStatus::Ignored, 'page_disabled');
            }

            if ($lead === null) {
                if (! $page->integration?->isConnected()) {
                    throw MetaApiException::of(MetaErrorCategory::Configuration, 'The Meta integration is disconnected. Reconnect, then retry this event.', 'integration_disconnected');
                }
                $lead = $this->leads->fetch($page, $event->leadgen_id);
            } else {
                $lead = $this->leads->validate($lead, $event->leadgen_id);
            }

            $form = $this->form($page, $event, $lead);
            if (! $form->is_enabled) {
                return $form->wasRecentlyCreated
                    ? $this->fail($event, MetaApiException::of(MetaErrorCategory::Configuration, "New form \"{$form->form_name}\" is awaiting review. Enable it and retry this event.", 'form_pending_review'))
                    : $this->finish($event, FacebookEventStatus::Ignored, 'form_disabled');
            }

            return $this->ingest($event, $page, $form, $lead);
        } catch (MetaApiException $e) {
            return $this->fail($event, $e);
        } catch (\Throwable $e) {
            // Exception messages may embed SQL bindings (lead PII): store/log the class only.
            Log::error('Meta lead ingestion error', ['leadgen_id' => $event->leadgen_id, 'event_id' => $event->id, 'error' => class_basename($e), 'at' => basename($e->getFile()).':'.$e->getLine()]);

            return $this->fail($event, new MetaApiException(MetaErrorCategory::Validation, 'The lead could not be saved ('.class_basename($e).').', 'crm_error'));
        }
    }

    /**
     * Admin retry of a failed event: a fresh attempt budget, re-queued through
     * the same pipeline. Auth/permission failures need a healthy connection first.
     *
     * @throws \RuntimeException when the event cannot be retried yet
     */
    public function retry(FacebookWebhookEvent $event, ?User $actor): void
    {
        if (! $event->isFailed()) {
            throw new \RuntimeException('Only failed events can be retried.');
        }

        $needsConnection = in_array($event->error_category, [MetaErrorCategory::Authentication, MetaErrorCategory::Permission, MetaErrorCategory::PageUnavailable], true)
            || $event->error_code === 'integration_disconnected';
        if ($needsConnection && ! $this->integrations->current()?->isHealthy()) {
            throw new \RuntimeException('Reconnect the Meta account (or run "Test connection") before retrying this event.');
        }

        $updated = FacebookWebhookEvent::query()
            ->whereKey($event->id)
            ->where('processing_status', FacebookEventStatus::Failed->value)
            ->update([
                'processing_status' => FacebookEventStatus::Queued->value,
                'attempt_count' => 0,
                'failed_at' => null,
                'next_attempt_at' => null,
            ]);

        if ($updated !== 1) {
            return;
        }

        $this->audit->log(AuditAction::FacebookWebhookRetried, 'integrations', $event, "Meta lead {$event->leadgen_id} retry requested", null, [
            'leadgen_id' => $event->leadgen_id,
            'previous_category' => $event->error_category?->value,
        ], $actor?->id);

        app(MetaWebhookService::class)->dispatch($event);
    }

    /**
     * Re-dispatches events whose job was lost: queued past their retry time
     * or stuck in "processing" (worker crash). Safe because claim() is atomic.
     */
    public function requeueStuck(): int
    {
        $ids = FacebookWebhookEvent::query()
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->whereIn('processing_status', [FacebookEventStatus::Queued->value, FacebookEventStatus::Received->value])
                    ->where(fn ($t) => $t->whereNull('next_attempt_at')->where('received_at', '<', now()->subMinutes(self::STALE_PROCESSING_MINUTES))
                        ->orWhere('next_attempt_at', '<', now()->subMinutes(self::STALE_PROCESSING_MINUTES))))
                ->orWhere(fn ($s) => $s->where('processing_status', FacebookEventStatus::Processing->value)
                    ->where('processing_started_at', '<', now()->subMinutes(self::STALE_PROCESSING_MINUTES))))
            ->limit(200)
            ->pluck('id');

        $webhooks = app(MetaWebhookService::class);
        FacebookWebhookEvent::query()->whereIn('id', $ids)->get()->each(fn ($event) => $webhooks->dispatch($event));

        return $ids->count();
    }

    /** Atomically moves a claimable event to "processing" and counts the attempt. */
    private function claim(FacebookWebhookEvent $event): bool
    {
        $claimed = FacebookWebhookEvent::query()
            ->whereKey($event->id)
            ->where(fn ($q) => $q
                ->whereIn('processing_status', [FacebookEventStatus::Received->value, FacebookEventStatus::Queued->value])
                ->orWhere(fn ($s) => $s->where('processing_status', FacebookEventStatus::Processing->value)
                    ->where('processing_started_at', '<', now()->subMinutes(self::STALE_PROCESSING_MINUTES))))
            ->update([
                'processing_status' => FacebookEventStatus::Processing->value,
                'processing_started_at' => now(),
                'attempt_count' => DB::raw('attempt_count + 1'),
                'next_attempt_at' => null,
            ]);

        return $claimed === 1;
    }

    private function page(FacebookWebhookEvent $event): ?FacebookPage
    {
        $page = $event->facebook_page_id ? FacebookPage::query()->with('integration')->find($event->facebook_page_id) : null;

        return $page ?? FacebookPage::query()->with('integration')->where('page_id', $event->page_id)->whereHas('integration')->latest('id')->first();
    }

    /** @throws MetaApiException */
    private function form(FacebookPage $page, FacebookWebhookEvent $event, array $lead): FacebookForm
    {
        $formId = (string) ($lead['form_id'] ?? $event->form_id ?? '');
        if ($formId === '') {
            throw MetaApiException::of(MetaErrorCategory::Malformed, 'The lead does not reference a form.', 'missing_form');
        }

        $form = FacebookForm::query()->where('facebook_page_id', $page->id)->where('form_id', $formId)->first();

        // Unknown form on a receiving Page: register it from Meta instead of dropping the lead.
        return $form ?? $this->forms->syncOne($page, $formId);
    }

    /** @return array{status: string, retry_in: ?int, lead_id: ?int} */
    private function ingest(FacebookWebhookEvent $event, FacebookPage $page, FacebookForm $form, array $lead): array
    {
        $mapped = $this->mapping->apply($form, $lead['field_data']);
        [$custom, $rejected] = $this->customFields->validateInbound($mapped['custom']);

        $platform = strtolower((string) ($lead['platform'] ?? '')) === 'ig' ? 'ig' : 'fb';
        $channelName = $platform === 'ig' ? 'Instagram' : 'Facebook';
        $sourceId = $this->forms->sourceFor($form, $platform);
        $campaign = $this->campaigns->resolve($lead, $sourceId);
        $submittedAt = $this->submittedAt($lead) ?? $event->meta_created_at ?? now();

        $data = [
            ...$mapped['lead'],
            'source_id' => $sourceId,
            'campaign_id' => $campaign?->id,
            'custom_fields' => $custom,
            'facebook_lead_id' => $event->leadgen_id,
            'facebook_form_id' => $form->form_id,
            'facebook_page_id' => $page->page_id,
            'facebook_ad_id' => $this->id($lead['ad_id'] ?? null),
            'facebook_adset_id' => $this->id($lead['adset_id'] ?? null),
            'facebook_campaign_id' => $this->id($lead['campaign_id'] ?? null),
        ];

        $metadata = array_filter([
            'platform' => $platform,
            'page_id' => $page->page_id,
            'page_name' => $page->page_name,
            'form_id' => $form->form_id,
            'form_name' => $form->form_name,
            'campaign_id' => $data['facebook_campaign_id'],
            'campaign_name' => $campaign?->name,
            'adset_id' => $data['facebook_adset_id'],
            'adset_name' => is_string($lead['adset_name'] ?? null) ? mb_substr($lead['adset_name'], 0, 191) : null,
            'ad_id' => $data['facebook_ad_id'],
            'ad_name' => is_string($lead['ad_name'] ?? null) ? mb_substr($lead['ad_name'], 0, 191) : null,
            'is_organic' => isset($lead['is_organic']) ? (bool) $lead['is_organic'] : null,
            'labels' => $mapped['labels'],
            'unmapped' => $mapped['unmapped'] ?: null,
            'rejected_custom_fields' => $rejected ?: null,
            'truncated' => $mapped['truncated'] ?: null,
            'origin' => $event->origin,
            'received_by_crm_at' => now()->toIso8601String(),
        ], fn ($v) => $v !== null);

        $campaignText = $campaign ? " (campaign \"{$campaign->name}\")" : '';

        $outcome = DB::transaction(function () use ($event, $data, $mapped, $metadata, $submittedAt, $form, $channelName, $campaignText) {
            $locked = FacebookWebhookEvent::query()->whereKey($event->id)->lockForUpdate()->first();
            if (! $locked || $locked->lead_id || $locked->processing_status?->isFinal()) {
                return null;
            }

            $existingEnquiry = LeadEnquiry::query()->where('channel', LeadEnquiry::CHANNEL_FACEBOOK)->where('external_id', $event->leadgen_id)->first();
            $existingLeadId = $existingEnquiry?->lead_id ?? Lead::withTrashed()->where('facebook_lead_id', $event->leadgen_id)->value('id');
            if ($existingLeadId) {
                $this->markFinal($locked, FacebookEventStatus::Duplicate, 'already_ingested', (int) $existingLeadId, $existingEnquiry?->id);

                return ['status' => FacebookEventStatus::Duplicate, 'lead' => null, 'merged' => false];
            }

            $result = $this->leadService->createFromInbound($data, AssignmentType::Facebook, $mapped['answers'], [
                'enquiry' => [
                    'channel' => LeadEnquiry::CHANNEL_FACEBOOK,
                    'external_id' => $event->leadgen_id,
                    'metadata' => $metadata,
                    'received_at' => $submittedAt,
                ],
                'fill_missing' => true,
                'created_activity' => "Lead received from {$channelName} form \"{$form->form_name}\"{$campaignText}.",
                'merged_activity' => "Duplicate {$channelName} enquiry received from form \"{$form->form_name}\"{$campaignText}.",
                'audit' => ['leadgen_id' => $event->leadgen_id, 'facebook_form_id' => $form->form_id, 'facebook_page_id' => $data['facebook_page_id']],
            ]);

            /** @var Lead $leadModel */
            $leadModel = $result['lead'];
            $outcome = $result['merged'] ? 'merged' : ($leadModel->is_duplicate ? 'flagged' : 'created');

            $this->markFinal($locked, FacebookEventStatus::Processed, null, $leadModel->id, $result['enquiry']->id, $outcome);
            $form->forceFill(['last_lead_at' => now()])->saveQuietly();
            FacebookIntegration::query()->whereKey($form->page?->facebook_integration_id)->update(['last_lead_at' => now()]);

            $this->audit->log(
                $result['merged'] ? AuditAction::FacebookEnquiryCreated : AuditAction::FacebookLeadCreated,
                'integrations',
                $leadModel,
                $result['merged'] ? "Meta enquiry merged into {$leadModel->lead_number}" : "Lead {$leadModel->lead_number} created from Meta",
                null,
                ['leadgen_id' => $event->leadgen_id, 'form_id' => $form->form_id, 'page_id' => $data['facebook_page_id'], 'enquiry_id' => $result['enquiry']->id, 'outcome' => $outcome, 'assigned_to' => $leadModel->assigned_to],
                null,
            );

            return ['status' => FacebookEventStatus::Processed, 'lead' => $leadModel->fresh(), 'merged' => $result['merged'], 'campaign' => $campaignText];
        });

        if ($outcome === null) {
            return $this->result($event->fresh());
        }

        if ($outcome['lead']) {
            $this->notify($outcome['lead'], $outcome['merged'], $channelName, $form);
        }

        return $this->result($event->fresh());
    }

    /**
     * Sent only by the invocation that performed the ingestion (inside the
     * locked transaction above), so retries can never notify twice.
     */
    private function notify(Lead $lead, bool $merged, string $channelName, FacebookForm $form): void
    {
        if (! $lead->assigned_to) {
            return;
        }
        if ($merged ? ! $this->settings->get('facebook.notify_on_repeat_enquiry', true) : ! $this->settings->get('notifications.notify_on_assignment', true)) {
            return;
        }

        $assignee = User::query()->active()->find($lead->assigned_to);
        $assignee?->notify(new FacebookLeadNotification($lead, $merged ? 'enquiry' : 'assigned', $channelName, $form->form_name, $lead->campaign?->name));
    }

    /** @return array{status: string, retry_in: ?int, lead_id: ?int} */
    private function fail(FacebookWebhookEvent $event, MetaApiException $e): array
    {
        $event->refresh();
        $maxAttempts = max(1, (int) config('meta.max_attempts', 5));
        $retryable = $e->isRetryable() && $event->attempt_count < $maxAttempts;

        if ($retryable) {
            $backoff = config('meta.backoff', [60]);
            $delay = $e->retryAfter ?? (int) ($backoff[min($event->attempt_count - 1, count($backoff) - 1)] ?? 60);

            $event->forceFill([
                'processing_status' => FacebookEventStatus::Queued,
                'next_attempt_at' => now()->addSeconds($delay),
                'error_category' => $e->category,
                'error_code' => mb_substr($e->code(), 0, 50),
                'error_message' => $e->getMessage(),
            ])->save();

            return ['status' => FacebookEventStatus::Queued->value, 'retry_in' => $delay, 'lead_id' => null];
        }

        $event->forceFill([
            'processing_status' => FacebookEventStatus::Failed,
            'failed_at' => now(),
            'next_attempt_at' => null,
            'error_category' => $e->category,
            'error_code' => mb_substr($e->code(), 0, 50),
            'error_message' => $e->getMessage(),
        ])->save();

        if (in_array($e->category, [MetaErrorCategory::Authentication, MetaErrorCategory::Permission, MetaErrorCategory::PageUnavailable], true)) {
            $this->integrations->recordFailure($e);
        }

        $this->audit->log(AuditAction::FacebookWebhookFailed, 'integrations', $event, "Meta lead {$event->leadgen_id} failed: {$e->category->value}", null, [
            'leadgen_id' => $event->leadgen_id,
            'page_id' => $event->page_id,
            'form_id' => $event->form_id,
            'category' => $e->category->value,
            'error_code' => $e->code(),
            'attempts' => $event->attempt_count,
        ], null);

        return ['status' => FacebookEventStatus::Failed->value, 'retry_in' => null, 'lead_id' => null];
    }

    /** @return array{status: string, retry_in: ?int, lead_id: ?int} */
    private function finish(FacebookWebhookEvent $event, FacebookEventStatus $status, string $reason): array
    {
        $this->markFinal($event, $status, $reason);

        return $this->result($event->fresh());
    }

    private function markFinal(FacebookWebhookEvent $event, FacebookEventStatus $status, ?string $reason, ?int $leadId = null, ?int $enquiryId = null, ?string $outcome = null): void
    {
        $event->forceFill([
            'processing_status' => $status,
            'processed_at' => now(),
            'next_attempt_at' => null,
            'failed_at' => null,
            'error_category' => null,
            'error_code' => $reason,
            'error_message' => null,
            'lead_id' => $leadId ?? $event->lead_id,
            'lead_enquiry_id' => $enquiryId ?? $event->lead_enquiry_id,
            'outcome' => $outcome,
        ])->save();
    }

    /** @return array{status: string, retry_in: ?int, lead_id: ?int} */
    private function result(FacebookWebhookEvent $event): array
    {
        return ['status' => $event->processing_status?->value ?? 'unknown', 'retry_in' => null, 'lead_id' => $event->lead_id];
    }

    private function submittedAt(array $lead): ?Carbon
    {
        try {
            return ! empty($lead['created_time']) ? Carbon::parse((string) $lead['created_time']) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function id(mixed $value): ?string
    {
        return preg_match('/^\d{1,64}$/', (string) $value) === 1 ? (string) $value : null;
    }
}
