<?php

namespace App\Console\Commands;

use App\Enums\FacebookIntegrationStatus;
use App\Jobs\SyncMetaFormLeads;
use App\Models\FacebookForm;
use Illuminate\Console\Command;

/**
 * Pulls recent leads from Meta on a timer. This is the path used when the
 * Page webhook is not configured: each enabled form on a selected Page is
 * queued through the same ingestion as "Sync recent leads".
 */
class MetaPollLeads extends Command
{
    protected $signature = 'meta:poll-leads';

    protected $description = 'Queue a pull of recent Meta leads for every enabled form';

    public function handle(): int
    {
        $days = max(1, (int) config('meta.poll_days', 1));

        $formIds = FacebookForm::query()
            ->where('is_enabled', true)
            ->whereHas('page', fn ($page) => $page->receiving()->whereHas(
                'integration',
                fn ($integration) => $integration
                    ->where('status', '!=', FacebookIntegrationStatus::Disconnected)
                    ->whereNotNull('access_token_encrypted'),
            ))
            ->pluck('id');

        foreach ($formIds as $formId) {
            SyncMetaFormLeads::dispatch((int) $formId, $days);
        }

        $this->info("Queued a {$days}-day lead pull for {$formIds->count()} form(s).");

        return self::SUCCESS;
    }
}
