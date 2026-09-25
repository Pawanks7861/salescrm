<?php

namespace App\Console\Commands;

use App\Enums\FacebookEventStatus;
use App\Enums\MetaErrorCategory;
use App\Models\FacebookWebhookEvent;
use App\Services\Meta\MetaLeadIngestionService;
use Illuminate\Console\Command;

class MetaRetryFailed extends Command
{
    protected $signature = 'meta:retry-failed
        {--failed : Also retry FAILED events whose category is transient or fixable (rate limit, temporary, network, configuration)}
        {--id=* : Retry specific event ids}';

    protected $description = 'Re-dispatch stuck Meta lead events and optionally retry failed ones';

    public function handle(MetaLeadIngestionService $ingestion): int
    {
        $requeued = $ingestion->requeueStuck();
        $this->info("Re-dispatched {$requeued} stuck event(s).");

        $ids = array_filter(array_map('intval', (array) $this->option('id')));
        if (! $this->option('failed') && $ids === []) {
            return self::SUCCESS;
        }

        $retryable = [MetaErrorCategory::RateLimit, MetaErrorCategory::Temporary, MetaErrorCategory::Network, MetaErrorCategory::Configuration];

        $events = FacebookWebhookEvent::query()
            ->where('processing_status', FacebookEventStatus::Failed)
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids), fn ($q) => $q->whereIn('error_category', array_map(fn ($c) => $c->value, $retryable)))
            ->limit(500)
            ->get();

        $retried = 0;
        foreach ($events as $event) {
            try {
                $ingestion->retry($event, null);
                $retried++;
            } catch (\RuntimeException $e) {
                $this->warn("Event {$event->id}: {$e->getMessage()}");
            }
        }

        $this->info("Queued {$retried} failed event(s) for retry.");

        return self::SUCCESS;
    }
}
