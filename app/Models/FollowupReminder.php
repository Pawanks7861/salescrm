<?php

namespace App\Models;

use App\Support\ReminderState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scheduled alert for a follow-up. The row is the idempotency record:
 * it moves pending → processing (claimed atomically) → sent, so a reminder is
 * delivered at most once however often the scheduler runs.
 */
class FollowupReminder extends Model
{
    public const KIND_REMINDER = 'reminder';

    public const KIND_OVERDUE = 'overdue';

    public const PENDING = ReminderState::PENDING;

    public const PROCESSING = ReminderState::PROCESSING;

    public const SENT = ReminderState::SENT;

    public const CANCELLED = ReminderState::CANCELLED;

    public const FAILED = ReminderState::FAILED;

    public const MAX_ATTEMPTS = ReminderState::MAX_ATTEMPTS;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function followup(): BelongsTo
    {
        return $this->belongsTo(Followup::class)->withTrashed();
    }
}
