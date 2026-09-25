<?php

namespace App\Enums;

enum FacebookEventStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Received, self::Queued => 'blue',
            self::Processing => 'indigo',
            self::Processed => 'green',
            self::Failed => 'red',
            self::Ignored => 'slate',
            self::Duplicate => 'amber',
        };
    }

    /** Terminal states never processed again by retries or redeliveries. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Processed, self::Ignored, self::Duplicate], true);
    }
}
