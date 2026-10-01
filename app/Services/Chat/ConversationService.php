<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Notifications\Chat\ChatMessageNotification;
use App\Support\Permissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    public const LIST_LIMIT = 100;

    /** Whether $me may open (or keep using) a direct conversation with $other. */
    public function canChatWith(User $me, User $other): bool
    {
        return $me->id !== $other->id
            && ! $other->trashed()
            && $other->is_active
            && $other->hasPermission(Permissions::CHAT_USE);
    }

    /**
     * Returns the single direct conversation for the pair, creating it when
     * missing. The unique direct_key plus insertOrIgnore makes concurrent
     * "New chat" clicks from both users converge on one row.
     */
    public function findOrCreateDirect(User $me, User $other): Conversation
    {
        $key = Conversation::directKey($me->id, $other->id);

        return DB::transaction(function () use ($key, $me, $other) {
            $now = now();

            DB::table('conversations')->insertOrIgnore([
                'type' => Conversation::TYPE_DIRECT,
                'direct_key' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $conversation = Conversation::where('direct_key', $key)->firstOrFail();

            DB::table('conversation_participants')->insertOrIgnore(
                collect([$me->id, $other->id])->map(fn (int $userId) => [
                    'conversation_id' => $conversation->id,
                    'user_id' => $userId,
                    'joined_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );

            return $conversation;
        });
    }

    /**
     * Conversation summaries for the sidebar list: other participant, presence,
     * last message preview and unread count — a fixed number of queries.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFor(User $me, ?int $includeConversationId = null): array
    {
        $conversations = Conversation::query()
            ->forUser($me->id)
            ->where(fn ($q) => $q->whereNotNull('last_message_id')
                ->when($includeConversationId, fn ($q) => $q->orWhere('conversations.id', $includeConversationId)))
            ->orderByRaw('last_message_at IS NULL')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        if ($conversations->isEmpty()) {
            return [];
        }

        $ids = $conversations->pluck('id')->all();

        $others = ConversationParticipant::query()
            ->whereIn('conversation_id', $ids)
            ->where('user_id', '!=', $me->id)
            ->with(['user' => fn ($q) => $q->select('id', 'name', 'designation', 'role_id', 'is_active', 'last_seen_at', 'deleted_at')->with('role:id,name,slug')])
            ->get()
            ->keyBy('conversation_id');

        $lastMessages = Message::withTrashed()
            ->whereIn('id', $conversations->pluck('last_message_id')->filter()->all())
            ->withCount('attachments')
            ->get()
            ->keyBy('id');

        $unread = $this->unreadCounts($me->id, $ids);

        return $conversations->map(function (Conversation $conversation) use ($others, $lastMessages, $unread, $me) {
            $other = $others->get($conversation->id)?->user;
            $last = $lastMessages->get($conversation->last_message_id);

            return [
                'id' => $conversation->id,
                'user' => $other ? ChatPresenter::user($other) : null,
                'last_message' => $last ? ChatPresenter::preview($last, $me->id) : null,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'unread' => $unread[$conversation->id] ?? 0,
            ];
        })->values()->all();
    }

    /**
     * Unread messages per conversation for one user, in a single grouped query.
     *
     * @param  array<int>|null  $conversationIds
     * @return array<int, int>
     */
    public function unreadCounts(int $userId, ?array $conversationIds = null): array
    {
        return $this->unreadQuery($userId)
            ->when($conversationIds !== null, fn ($q) => $q->whereIn('messages.conversation_id', $conversationIds))
            ->groupBy('messages.conversation_id')
            ->selectRaw('messages.conversation_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'messages.conversation_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function totalUnread(int $userId): int
    {
        return (int) $this->unreadQuery($userId)->count();
    }

    /**
     * Moves the reader's pointer to the newest message in the conversation.
     * The pointer only ever moves forward, so a stale tab cannot "unread" messages.
     */
    public function markRead(Conversation $conversation, User $reader): ?int
    {
        $latestId = Message::withTrashed()->where('conversation_id', $conversation->id)->max('id');

        if (! $latestId) {
            return null;
        }

        DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $reader->id)
            ->where(fn ($q) => $q->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', $latestId))
            ->update(['last_read_message_id' => $latestId, 'last_read_at' => now(), 'updated_at' => now()]);

        $reader->unreadNotifications()
            ->where('data->category', ChatMessageNotification::CATEGORY)
            ->where('data->conversation_id', $conversation->id)
            ->update(['read_at' => now()]);

        return (int) $latestId;
    }

    private function unreadQuery(int $userId)
    {
        return DB::table('messages')
            ->join('conversation_participants as cp', function ($join) use ($userId) {
                $join->on('cp.conversation_id', '=', 'messages.conversation_id')
                    ->where('cp.user_id', '=', $userId);
            })
            ->where('messages.sender_id', '!=', $userId)
            ->whereNull('messages.deleted_at')
            ->whereRaw('messages.id > COALESCE(cp.last_read_message_id, 0)');
    }

    /** @return Collection<int, User> */
    public function searchUsers(User $me, ?string $term, int $limit = 20): Collection
    {
        $term = trim((string) $term);

        return User::query()
            ->active()
            ->withPermission(Permissions::CHAT_USE)
            ->whereKeyNot($me->id)
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('designation', 'like', $like)
                    ->orWhere('employee_code', 'like', $like));
            })
            ->with('role:id,name,slug')
            ->orderByDesc('last_seen_at')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'designation', 'role_id', 'is_active', 'last_seen_at', 'deleted_at']);
    }
}
