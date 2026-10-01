<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    public const TYPE_DIRECT = 'direct';

    protected $fillable = ['type', 'direct_key', 'last_message_id', 'last_message_at'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')->withTrashed();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id')->withTrashed();
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->whereExists(fn ($q) => $q->selectRaw('1')
            ->from('conversation_participants')
            ->whereColumn('conversation_participants.conversation_id', 'conversations.id')
            ->where('conversation_participants.user_id', $userId));
    }

    public function hasParticipant(int $userId): bool
    {
        return $this->participants()->where('user_id', $userId)->exists();
    }

    public function participantFor(int $userId): ?ConversationParticipant
    {
        return $this->participants()->where('user_id', $userId)->first();
    }

    /** The other member of a direct conversation. */
    public function otherUser(int $userId): ?User
    {
        return $this->users()->where('users.id', '!=', $userId)->first();
    }
}
