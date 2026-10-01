<?php

namespace App\Notifications\Chat;

use App\Models\Message;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Contracts\BrowserPushable;
use App\Support\PushEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * New private chat message. The notification id is derived from the message
 * id, so one message can never produce two notifications for the recipient.
 * Only a short text preview is carried — never attachment content or links
 * to files. NotificationController re-checks participation before display.
 *
 * The database row is written in-request so the bell and unread state are
 * immediate; the outbound Web Push / FCM calls run on the queue so a slow
 * push service never delays sending the message.
 */
class ChatMessageNotification extends Notification implements BrowserPushable, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public const CATEGORY = 'chat';

    public const EVENT = 'chat_message';

    public const PREVIEW_LENGTH = 120;

    public function __construct(public Message $message, public User $sender, public bool $viewing = false)
    {
        $this->id = self::idFor($message);
    }

    public static function idFor(Message $message): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "crm:chat-message:{$message->id}")->toString();
    }

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class, FcmChannel::class];
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    /** A message deleted before its queued push runs is never pushed. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($channel === 'database') {
            return true;
        }

        $current = $this->message->fresh();

        return $current !== null && ! $current->trashed();
    }

    public function toArray(object $notifiable): array
    {
        $preview = $this->preview();

        return [
            'category' => self::CATEGORY,
            'event' => self::EVENT,
            'message' => $preview === null
                ? "{$this->sender->name} sent you an attachment."
                : "{$this->sender->name} sent you a message: {$preview}",
            'sender_id' => $this->sender->id,
            'conversation_id' => $this->message->conversation_id,
            'message_id' => $this->message->id,
            'preview' => $preview,
            'url' => route('chat.show', $this->message->conversation_id, false),
        ];
    }

    /** No intrusive push while the recipient is already looking at this conversation. */
    public function toBrowserPush(object $notifiable): ?array
    {
        if ($this->viewing) {
            return null;
        }

        $preview = $this->preview();

        return [
            'event' => PushEvent::CHAT_MESSAGE,
            'title' => "{$this->sender->name} sent you a message",
            'body' => $preview ?? "{$this->sender->name} sent you an attachment.",
        ];
    }

    private function preview(): ?string
    {
        $body = trim((string) $this->message->body);

        return $body === '' ? null : Str::limit(preg_replace('/\s+/u', ' ', $body) ?? '', self::PREVIEW_LENGTH);
    }
}
