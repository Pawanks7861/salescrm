<?php

namespace App\Notifications\Channels;

use App\Jobs\SendWebPushNotification;
use App\Models\User;
use App\Notifications\Contracts\BrowserPushable;
use App\Services\Notifications\WebPushService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Extra delivery on top of the database channel (listed after it in via()).
 * It only queues a job, after the surrounding transaction commits, and never
 * throws: a push problem must not fail lead creation or a follow-up reminder,
 * and must not cause the in-app notification to be re-sent.
 */
class WebPushChannel
{
    public function __construct(private readonly WebPushService $push) {}

    public function send(object $notifiable, Notification $notification): void
    {
        try {
            if (! $notifiable instanceof User || ! $notification instanceof BrowserPushable || ! $this->push->shouldPush($notifiable)) {
                return;
            }

            $message = $notification->toBrowserPush($notifiable);
            if ($message === null) {
                return;
            }

            SendWebPushNotification::dispatch($notifiable->id, self::payload(
                $notification->id,
                $message['event'],
                $message['title'],
                $message['body'],
                route('notifications.open', $notification->id, false),
            ))->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('Browser push could not be queued', [
                'user_id' => $notifiable->id ?? null,
                'notification_id' => $notification->id,
                'error' => class_basename($e),
            ]);
        }
    }

    /**
     * The only fields a push may carry. The same id is the database
     * notification id, the OS notification tag and the frontend de-dup key.
     *
     * @return array{id: string, event: string, title: string, body: string, url: string}
     */
    public static function payload(string $id, string $event, string $title, string $body, string $url): array
    {
        return [
            'id' => $id,
            'event' => $event,
            'title' => mb_substr($title, 0, 80),
            'body' => mb_substr($body, 0, 180),
            'url' => $url,
        ];
    }
}
