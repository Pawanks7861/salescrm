<?php

namespace App\Notifications\Batches;

use App\Models\Batch;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Contracts\BrowserPushable;
use App\Support\PushEvent;
use Illuminate\Notifications\Notification;

/**
 * Tells a trainer they were assigned to (bell + browser push) or removed from
 * (bell only) a batch. Display data only: NotificationController re-checks
 * that the batch still exists and the user may view batches before linking.
 */
class BatchTrainerNotification extends Notification implements BrowserPushable
{
    public const ASSIGNED = 'batch_trainer_assigned';

    public const REMOVED = 'batch_trainer_removed';

    public function __construct(public Batch $batch, public bool $assigned = true) {}

    public function via(object $notifiable): array
    {
        return $this->assigned ? ['database', WebPushChannel::class, FcmChannel::class] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'category' => 'batch',
            'event' => $this->assigned ? self::ASSIGNED : self::REMOVED,
            'message' => $this->message(),
            'batch_id' => $this->batch->id,
            'url' => route('batches.show', $this->batch->id, false),
        ];
    }

    public function toBrowserPush(object $notifiable): ?array
    {
        return $this->assigned
            ? ['event' => PushEvent::BATCH_ASSIGNED, 'title' => 'Batch assigned', 'body' => $this->message()]
            : null;
    }

    private function message(): string
    {
        return $this->assigned
            ? "You have been assigned to Batch \"{$this->batch->name}\"."
            : "You have been removed from Batch \"{$this->batch->name}\".";
    }
}
