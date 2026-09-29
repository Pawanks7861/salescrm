<?php

namespace App\Notifications\Channels;

use App\Jobs\SendFcmNotification;
use App\Models\User;
use App\Notifications\Contracts\BrowserPushable;
use App\Services\Notifications\FcmService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Extra delivery beside Web Push. Queues after the surrounding transaction
 * commits and never throws, so a Firebase problem cannot fail lead
 * assignment or a follow-up reminder.
 */
class FcmChannel
{
    public function __construct(private readonly FcmService $fcm) {}

    public function send(object $notifiable, Notification $notification): void
    {
        try {
            if (! $notifiable instanceof User || ! $notification instanceof BrowserPushable || ! $this->fcm->shouldSend($notifiable)) {
                return;
            }

            $message = $notification->toBrowserPush($notifiable);
            if ($message === null) {
                return;
            }

            SendFcmNotification::dispatch($notifiable->id, WebPushChannel::payload(
                $notification->id,
                $message['event'],
                $message['title'],
                $message['body'],
                route('notifications.open', $notification->id, false),
            ))->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('FCM could not be queued', [
                'user_id' => $notifiable->id ?? null,
                'notification_id' => $notification->id,
                'error' => class_basename($e),
            ]);
        }
    }
}
