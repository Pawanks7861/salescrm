<?php

namespace App\Models;

use App\Support\ReminderState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One in-app reminder for one recipient; the status is the idempotency guard (see ReminderQueue). */
class MeetingReminder extends Model
{
    public const CHANNEL_DATABASE = 'database';

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
            'failed_at' => 'datetime',
            'attempts' => 'integer',
            'minutes_before' => 'integer',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
