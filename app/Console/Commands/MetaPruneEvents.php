<?php

namespace App\Console\Commands;

use App\Enums\FacebookEventStatus;
use App\Models\FacebookWebhookEvent;
use App\Services\SettingService;
use Illuminate\Console\Command;

/**
 * Removes old COMPLETED ledger rows (they hold ids only). Failed/pending
 * events are kept for review. Leads and enquiries are never touched; the
 * enquiry's unique (channel, external_id) keeps idempotency after pruning.
 */
class MetaPruneEvents extends Command
{
    protected $signature = 'meta:prune-events';

    protected $description = 'Delete completed Meta webhook events older than the retention setting';

    public function handle(SettingService $settings): int
    {
        $days = max(7, (int) $settings->get('facebook.event_retention_days', 180));

        $deleted = FacebookWebhookEvent::query()
            ->whereIn('processing_status', [FacebookEventStatus::Processed->value, FacebookEventStatus::Ignored->value, FacebookEventStatus::Duplicate->value])
            ->where('received_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} event(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
