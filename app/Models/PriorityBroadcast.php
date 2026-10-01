<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriorityBroadcast extends Model
{
    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = ['title', 'message', 'priority', 'sent_by', 'expires_at', 'recipients_count'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'recipients_count' => 'integer',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by')->withTrashed();
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(PriorityBroadcastRecipient::class, 'broadcast_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('priority_broadcasts.expires_at')
            ->orWhere('priority_broadcasts.expires_at', '>', now()));
    }
}
