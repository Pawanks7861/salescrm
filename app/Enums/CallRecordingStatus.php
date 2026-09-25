<?php

namespace App\Enums;

enum CallRecordingStatus: string
{
    case Pending = 'pending';
    case Available = 'available';
    case Failed = 'failed';
    case Expired = 'expired';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Processing',
            self::Available => 'Available',
            self::Failed => 'Recording unavailable',
            self::Expired => 'Expired',
            self::Deleted => 'Deleted',
        };
    }
}
