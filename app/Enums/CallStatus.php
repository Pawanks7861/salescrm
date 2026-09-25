<?php

namespace App\Enums;

/**
 * CRM call lifecycle. Provider states are translated into these by the
 * provider adapter and never leak further into the CRM.
 *
 *   initiated → queued → dialing → ringing → answered → completed
 *                                         ↘ busy | no_answer | failed | cancelled | missed
 *
 * Transitions only move forward by rank; terminal states are final. This is
 * what stops a delayed "ringing" callback from downgrading a completed call.
 */
enum CallStatus: string
{
    case Initiated = 'initiated';
    case Queued = 'queued';
    case Dialing = 'dialing';
    case Ringing = 'ringing';
    case Answered = 'answered';
    case Completed = 'completed';
    case Busy = 'busy';
    case NoAnswer = 'no_answer';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Missed = 'missed';

    public function label(): string
    {
        return match ($this) {
            self::NoAnswer => 'No answer',
            default => ucfirst($this->value),
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Initiated => 0,
            self::Queued => 1,
            self::Dialing => 2,
            self::Ringing => 3,
            self::Answered => 4,
            default => 10,
        };
    }

    public function isTerminal(): bool
    {
        return $this->rank() === 10;
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /** The customer and the agent actually spoke. */
    public function isConnected(): bool
    {
        return in_array($this, [self::Answered, self::Completed], true);
    }

    public function canTransitionTo(self $next): bool
    {
        return ! $this->isTerminal() && $next->rank() > $this->rank();
    }

    public function color(): string
    {
        return match ($this) {
            self::Completed => 'green',
            self::Answered => 'blue',
            self::Missed, self::Failed => 'red',
            self::Busy, self::NoAnswer => 'amber',
            self::Cancelled => 'slate',
            default => 'indigo',
        };
    }

    /** @return array<string> */
    public static function openValues(): array
    {
        return array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->isOpen()));
    }

    /** @return array<string> */
    public static function connectedValues(): array
    {
        return [self::Answered->value, self::Completed->value];
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
