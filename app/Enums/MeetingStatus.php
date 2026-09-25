<?php

namespace App\Enums;

/**
 * Stored meeting states. "Today", "upcoming" and "past" are derived from
 * start_at / end_at at read time and are never stored.
 *
 *   scheduled ─confirm─▶ confirmed ─start─▶ in_progress ─complete─▶ completed
 *   scheduled|confirmed|in_progress ─complete──────────────────────▶ completed
 *   scheduled|confirmed ─cancel─▶ cancelled | ─no-show─▶ no_show
 *   scheduled|confirmed ─reschedule─▶ rescheduled (+ NEW scheduled meeting)
 */
enum MeetingStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Rescheduled = 'rescheduled';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            self::NoShow => 'No-show',
            default => ucfirst($this->value),
        };
    }

    /** Scheduling can still change (edit, reschedule, cancel, no-show). */
    public function isUpcoming(): bool
    {
        return in_array($this, [self::Scheduled, self::Confirmed], true);
    }

    /** Not yet closed: can still be completed. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Scheduled, self::Confirmed, self::InProgress], true);
    }

    /** Statuses that never block a calendar slot. */
    public static function nonBlocking(): array
    {
        return [self::Cancelled->value, self::Rescheduled->value];
    }

    /** @return array<string> */
    public static function openValues(): array
    {
        return [self::Scheduled->value, self::Confirmed->value, self::InProgress->value];
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
