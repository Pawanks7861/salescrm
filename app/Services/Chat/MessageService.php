<?php

namespace App\Services\Chat;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\Chat\ChatMessageNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageService
{
    public const DISK = 'local';

    public const PAGE_SIZE = 40;

    /** Seconds a "typing…" signal stays visible without a refresh. */
    public const TYPING_TTL = 6;

    /** Seconds a recipient counts as "looking at this conversation" after a poll. */
    public const VIEWING_TTL = 12;

    /**
     * Persists the message (and its files) first; the notification is only
     * created once the transaction has committed, so nobody is ever notified
     * about a message that does not exist.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function send(Conversation $conversation, User $sender, ?string $body, ?int $replyToId, array $files = []): Message
    {
        $body = self::cleanBody($body);

        if ($body === null && $files === []) {
            throw ValidationException::withMessages(['message' => 'Type a message or attach a file.']);
        }

        if ($replyToId !== null && ! Message::withTrashed()->whereKey($replyToId)->where('conversation_id', $conversation->id)->exists()) {
            throw ValidationException::withMessages(['reply_to_message_id' => 'You can only reply to a message in this conversation.']);
        }

        $stored = [];

        try {
            foreach ($files as $file) {
                $stored[] = $this->storeFile($conversation, $file);
            }

            $message = DB::transaction(function () use ($conversation, $sender, $body, $replyToId, $stored) {
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $sender->id,
                    'body' => $body,
                    'reply_to_message_id' => $replyToId,
                ]);

                foreach ($stored as $file) {
                    $attachment = new Attachment;
                    $attachment->forceFill([
                        'attachable_type' => $message->getMorphClass(),
                        'attachable_id' => $message->id,
                        ...$file,
                        'uploaded_by' => $sender->id,
                    ])->save();
                }

                DB::table('conversations')->where('id', $conversation->id)->update([
                    'last_message_id' => $message->id,
                    'last_message_at' => $message->created_at,
                    'updated_at' => now(),
                ]);

                DB::table('conversation_participants')
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', $sender->id)
                    ->update(['last_read_message_id' => $message->id, 'last_read_at' => now(), 'updated_at' => now()]);

                return $message;
            });
        } catch (\Throwable $e) {
            foreach ($stored as $file) {
                Storage::disk(self::DISK)->delete($file['path']);
            }
            throw $e;
        }

        Cache::forget($this->typingKey($conversation->id, $sender->id));
        $this->notifyRecipients($conversation, $message, $sender);

        return $message->load(['attachments', 'replyTo']);
    }

    public function edit(Message $message, string $body): Message
    {
        $body = self::cleanBody($body);

        if ($body === null) {
            throw ValidationException::withMessages(['message' => 'The message cannot be empty.']);
        }

        if ($body !== $message->body) {
            $message->forceFill(['body' => $body, 'edited_at' => now()])->save();
        }

        return $message;
    }

    /**
     * Soft delete: the row (and any files) are kept, but the text and
     * attachments are no longer returned or downloadable.
     */
    public function delete(Message $message): void
    {
        $message->delete();

        DB::table('notifications')->where('id', ChatMessageNotification::idFor($message))->delete();
    }

    /**
     * One page of messages, oldest first. $before loads history; $after returns
     * only messages newer than what the client already holds.
     *
     * @return Collection<int, Message>
     */
    public function page(Conversation $conversation, ?int $before = null, ?int $after = null, int $limit = self::PAGE_SIZE): Collection
    {
        $query = Message::withTrashed()
            ->where('conversation_id', $conversation->id)
            ->with(['attachments', 'replyTo']);

        if ($after !== null) {
            return $query->where('id', '>', $after)->orderBy('id')->limit(200)->get();
        }

        return $query
            ->when($before !== null, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Messages the client already holds that were edited or deleted since its
     * last sync.
     *
     * @return Collection<int, Message>
     */
    public function changedSince(Conversation $conversation, \DateTimeInterface $since, int $upToId): Collection
    {
        return Message::withTrashed()
            ->where('conversation_id', $conversation->id)
            ->where('id', '<=', $upToId)
            ->where('updated_at', '>=', $since)
            ->with(['attachments', 'replyTo'])
            ->orderBy('id')
            ->limit(200)
            ->get();
    }

    public function markTyping(Conversation $conversation, User $user): void
    {
        Cache::put($this->typingKey($conversation->id, $user->id), true, self::TYPING_TTL);
    }

    public function isTyping(Conversation $conversation, int $userId): bool
    {
        return (bool) Cache::get($this->typingKey($conversation->id, $userId), false);
    }

    public function markViewing(Conversation $conversation, User $user): void
    {
        Cache::put($this->viewingKey($conversation->id, $user->id), true, self::VIEWING_TTL);
    }

    public function isViewing(int $conversationId, int $userId): bool
    {
        return (bool) Cache::get($this->viewingKey($conversationId, $userId), false);
    }

    public function download(Attachment $attachment, bool $inline): StreamedResponse
    {
        $headers = [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];

        $disk = Storage::disk($attachment->disk);

        return $inline
            ? $disk->response($attachment->path, $attachment->original_name, $headers, 'inline')
            : $disk->download($attachment->path, $attachment->original_name, $headers);
    }

    /** Plain text only: trims, drops control characters, and turns blank input into null. */
    public static function cleanBody(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $body = preg_replace('/[^\P{Cc}\n\t]/u', '', str_replace("\r\n", "\n", $body)) ?? '';
        $body = trim($body);

        return $body === '' ? null : $body;
    }

    private function notifyRecipients(Conversation $conversation, Message $message, User $sender): void
    {
        $recipients = $conversation->users()
            ->where('users.id', '!=', $sender->id)
            ->whereNull('users.deleted_at')
            ->where('users.is_active', true)
            ->get();

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new ChatMessageNotification(
                    $message,
                    $sender,
                    viewing: $this->isViewing($conversation->id, $recipient->id),
                ));
            } catch (\Throwable $e) {
                // The message is already saved; a notification failure must not lose it.
                report($e);
            }
        }
    }

    /** @return array<string, mixed> */
    private function storeFile(Conversation $conversation, UploadedFile $file): array
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $storedName = Str::uuid()->toString().'.'.$extension;
        $path = $file->storeAs("chat/{$conversation->id}", $storedName, self::DISK);

        return [
            'original_name' => Str::limit($this->safeName($file->getClientOriginalName()), 250, ''),
            'stored_name' => $storedName,
            'disk' => self::DISK,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^\pL\pN\s._()-]+/u', '_', basename(str_replace('\\', '/', $name))) ?? 'file';

        return trim($name) !== '' ? trim($name) : 'file';
    }

    private function typingKey(int $conversationId, int $userId): string
    {
        return "chat:typing:{$conversationId}:{$userId}";
    }

    private function viewingKey(int $conversationId, int $userId): string
    {
        return "chat:viewing:{$conversationId}:{$userId}";
    }
}
