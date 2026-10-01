<?php

namespace App\Services\Chat;

use App\Models\Attachment;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Shapes chat data for the browser from an explicit whitelist: no e-mail,
 * phone, password hash, storage path or disk ever leaves the server.
 */
final class ChatPresenter
{
    public const DELETED_TEXT = 'This message was deleted.';

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @return array<string, mixed> */
    public static function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'designation' => $user->designation,
            'role' => $user->relationLoaded('role') ? $user->role?->name : null,
            'active' => (bool) $user->is_active && ! $user->trashed(),
            ...PresenceService::present($user),
        ];
    }

    /** @return array<string, mixed> */
    public static function message(Message $message, int $viewerId): array
    {
        $deleted = $message->trashed();

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id' => $message->sender_id,
            'mine' => $message->sender_id === $viewerId,
            'body' => $deleted ? null : $message->body,
            'deleted' => $deleted,
            'edited' => ! $deleted && $message->edited_at !== null,
            'created_at' => $message->created_at?->toIso8601String(),
            'reply_to' => $message->reply_to_message_id && $message->relationLoaded('replyTo') && $message->replyTo
                ? [
                    'id' => $message->replyTo->id,
                    'sender_id' => $message->replyTo->sender_id,
                    'preview' => self::previewText($message->replyTo),
                ]
                : null,
            'attachments' => $deleted || ! $message->relationLoaded('attachments')
                ? []
                : $message->attachments->map(fn (Attachment $a) => self::attachment($a))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function attachment(Attachment $attachment): array
    {
        $isImage = in_array($attachment->mime_type, self::IMAGE_MIMES, true);

        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'size' => (int) $attachment->size,
            'mime' => $attachment->mime_type,
            'is_image' => $isImage,
            'url' => route('chat.attachments.show', $attachment, false),
            'preview_url' => $isImage ? route('chat.attachments.show', ['attachment' => $attachment, 'inline' => 1], false) : null,
        ];
    }

    /** @return array{text: string, mine: bool, deleted: bool, attachment: bool} */
    public static function preview(Message $message, int $viewerId): array
    {
        return [
            'text' => self::previewText($message),
            'mine' => $message->sender_id === $viewerId,
            'deleted' => $message->trashed(),
            'attachment' => ($message->attachments_count ?? 0) > 0,
        ];
    }

    public static function previewText(Message $message, int $limit = 80): string
    {
        if ($message->trashed()) {
            return self::DELETED_TEXT;
        }

        $body = trim((string) $message->body);

        if ($body === '') {
            return 'Attachment';
        }

        return Str::limit(preg_replace('/\s+/u', ' ', $body) ?? '', $limit);
    }
}
