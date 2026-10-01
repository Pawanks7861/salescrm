<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use App\Support\Permissions;

/** Only the sender may edit or delete their own message. */
class MessagePolicy
{
    public function update(User $user, Message $message): bool
    {
        return $this->ownsLiveMessage($user, $message) && trim((string) $message->body) !== '';
    }

    public function delete(User $user, Message $message): bool
    {
        return $this->ownsLiveMessage($user, $message);
    }

    private function ownsLiveMessage(User $user, Message $message): bool
    {
        return $user->hasPermission(Permissions::CHAT_USE)
            && ! $message->trashed()
            && $message->sender_id === $user->id
            && $message->conversation->hasParticipant($user->id);
    }
}
