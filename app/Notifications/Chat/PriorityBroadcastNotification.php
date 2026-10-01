<?php

namespace App\Notifications\Chat;

use App\Models\PriorityBroadcast;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Contracts\BrowserPushable;
use App\Support\PushEvent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Urgent company-wide message. One notification per broadcast per recipient:
 * the id is derived from both, so a retried delivery job cannot duplicate it.
 */
class PriorityBroadcastNotification extends Notification implements BrowserPushable
{
    public const CATEGORY = 'priority';

    public const EVENT = 'priority_broadcast';

    public function __construct(public PriorityBroadcast $broadcast, int $recipientId)
    {
        $this->id = self::idFor($broadcast->id, $recipientId);
    }

    public static function idFor(int $broadcastId, int $userId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "crm:priority-broadcast:{$broadcastId}:{$userId}")->toString();
    }

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class, FcmChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'category' => self::CATEGORY,
            'event' => self::EVENT,
            'message' => "Urgent: {$this->broadcast->title}",
            'priority_broadcast_id' => $this->broadcast->id,
            'sender_id' => $this->broadcast->sent_by,
            'priority' => $this->broadcast->priority,
            'url' => route('priority-broadcasts.show', $this->broadcast->id, false),
        ];
    }

    public function toBrowserPush(object $notifiable): ?array
    {
        return [
            'event' => PushEvent::PRIORITY_BROADCAST,
            'title' => 'Urgent: '.$this->broadcast->title,
            'body' => Str::limit(preg_replace('/\s+/u', ' ', $this->broadcast->message) ?? '', 160),
        ];
    }
}
