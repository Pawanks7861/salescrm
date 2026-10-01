<?php

namespace App\Jobs;

use App\Models\PriorityBroadcast;
use App\Services\Chat\PriorityBroadcastService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Delivers one chunk (≤100 users) of a priority broadcast. */
class DeliverPriorityBroadcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @param  array<int>  $userIds */
    public function __construct(public int $broadcastId, public array $userIds)
    {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(PriorityBroadcastService $service): void
    {
        $broadcast = PriorityBroadcast::find($this->broadcastId);

        if ($broadcast) {
            $service->deliver($broadcast, $this->userIds);
        }
    }
}
