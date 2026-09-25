<?php

namespace App\Support;

/** Meeting reminder offsets (minutes before start). Multiple can be selected. */
final class MeetingReminderOptions
{
    public const OPTIONS = [
        0 => 'At the start time',
        15 => '15 minutes before',
        30 => '30 minutes before',
        60 => '1 hour before',
        120 => '2 hours before',
        1440 => '1 day before',
    ];

    /** @return array<int, array{value: int, label: string}> */
    public static function options(): array
    {
        return collect(self::OPTIONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all();
    }

    public static function label(int $minutes): string
    {
        return self::OPTIONS[$minutes] ?? "{$minutes} minutes before";
    }
}
