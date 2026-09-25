<?php

namespace App\Enums;

/**
 * Stored follow-up states. "Overdue" and "Due soon" are NOT stored: they are
 * derived from `pending` + `scheduled_at` so no job ever rewrites records.
 */
enum FollowupStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    /** Replaced by a new follow-up (`rescheduled_from_id` points back here). */
    case Rescheduled = 'rescheduled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
