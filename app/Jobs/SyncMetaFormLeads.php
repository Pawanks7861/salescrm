<?php

namespace App\Jobs;

use App\Enums\FacebookEventStatus;
use App\Models\FacebookForm;
use App\Models\FacebookWebhookEvent;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\MetaFormService;
use App\Services\Meta\MetaLeadIngestionService;
use App\Services\Meta\MetaWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * "Sync Recent Leads": pulls a form's recent leads from Meta and feeds each
 * one through the SAME event ledger + ingestion pipeline as the webhook, so
 * leads already received by webhook are skipped by leadgen_id.
 */
class SyncMetaFormLeads implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $formId, public int $days)
    {
        $this->onQueue(config('meta.queue', 'integrations'));
    }

    public function uniqueId(): string
    {
        return (string) $this->formId;
    }

    public function handle(MetaFormService $forms, MetaWebhookService $webhooks, MetaLeadIngestionService $ingestion): void
    {
        $form = FacebookForm::query()->with('page.integration')->find($this->formId);
        if (! $form || ! $form->is_enabled || ! $form->page?->receivesLeads() || ! $form->page->integration?->isConnected()) {
            return;
        }

        $counts = ['new' => 0, 'known' => 0, 'failed' => 0];

        try {
            foreach ($forms->recentLeads($form, $this->days) as $lead) {
                [$event, $outcome] = $webhooks->register([
                    'leadgen_id' => $lead['id'] ?? null,
                    'page_id' => $form->page->page_id,
                    'form_id' => $form->form_id,
                    'ad_id' => $lead['ad_id'] ?? null,
                    'created_time' => $lead['created_time'] ?? null,
                ], FacebookWebhookEvent::ORIGIN_SYNC, dispatch: false);

                if ($outcome !== 'accepted' || ! $event) {
                    $counts['known']++;

                    continue;
                }

                $result = $ingestion->process($event, $lead);
                if ($result['status'] === FacebookEventStatus::Queued->value) {
                    $webhooks->dispatch($event, (int) $result['retry_in']);
                }
                $result['status'] === FacebookEventStatus::Processed->value ? $counts['new']++ : $counts['failed']++;
            }
        } catch (MetaApiException $e) {
            Log::warning('Meta lead sync stopped', ['form_id' => $form->form_id, 'category' => $e->category->value, 'error_code' => $e->code()]);
        }

        Log::info('Meta lead sync finished', ['form_id' => $form->form_id, 'days' => $this->days, ...$counts]);
    }
}
