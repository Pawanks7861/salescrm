<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Support\Permissions;

/**
 * Private chat is participant-only. No permission — including Admin or Super
 * Admin — grants access to someone else's conversation.
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $user->hasPermission(Permissions::CHAT_USE) && $conversation->hasParticipant($user->id);
    }

    public function send(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
