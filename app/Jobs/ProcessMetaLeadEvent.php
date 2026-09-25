<?php

namespace App\Jobs;

use App\Enums\FacebookEventStatus;
use App\Models\FacebookWebhookEvent;
use App\Services\Meta\MetaLeadIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue wrapper around the single ingestion pipeline. Retry bookkeeping
 * (attempts, backoff, dead-letter) lives on the event row, so the job only
 * releases itself when the pipeline asks for a delayed retry.
 */
class ProcessMetaLeadEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(public int $eventId)
    {
        $this->onQueue(config('meta.queue', 'integrations'));
    }

    /** Attempts are bounded by meta.max_attempts on the event row; this is a safety net. */
    public function tries(): int
    {
        return max(1, (int) config('meta.max_attempts', 5)) + 1;
    }

    public function handle(MetaLeadIngestionService $ingestion): void
    {
        $event = FacebookWebhookEvent::query()->find($this->eventId);
        if (! $event || $event->processing_status?->isFinal() || $event->processing_status === FacebookEventStatus::Failed) {
            return;
        }

        $result = $ingestion->process($event);

        if ($result['status'] === FacebookEventStatus::Queued->value && $result['retry_in'] !== null && $this->job) {
            $this->release($result['retry_in']);
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('Meta lead job failed', ['event_id' => $this->eventId, 'error' => $e ? class_basename($e) : null]);
    }
}
