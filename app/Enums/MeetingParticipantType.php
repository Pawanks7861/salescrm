<?php

namespace App\Enums;

enum MeetingParticipantType: string
{
    case User = 'user';
    case Lead = 'lead';
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Internal user',
            self::Lead => 'Lead',
            self::External => 'External',
        };
    }
}
