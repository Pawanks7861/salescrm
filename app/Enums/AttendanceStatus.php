<?php

namespace App\Enums;

/** Individual participant attendance — independent of the meeting status. */
enum AttendanceStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Attended = 'attended';
    case Absent = 'absent';
    case Declined = 'declined';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
