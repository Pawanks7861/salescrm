<?php

namespace App\Support;

/**
 * Reminder choices shown in forms. `null` = no reminder, `0` = at the
 * scheduled time; any other whole number of minutes (up to 7 days) is valid.
 */
final class FollowupReminderOptions
{
    public const MAX_MINUTES = 10080;

    public const PRESETS = [0, 15, 30, 60, 120, 1440];

    public static function label(?int $minutes): string
    {
        return match (true) {
            $minutes === null => 'No reminder',
            $minutes === 0 => 'At the scheduled time',
            $minutes % 1440 === 0 => ($minutes / 1440).' day'.($minutes === 1440 ? '' : 's').' before',
            $minutes % 60 === 0 => ($minutes / 60).' hour'.($minutes === 60 ? '' : 's').' before',
            default => "{$minutes} minutes before",
        };
    }

    /** @return array<int, array{value: int|null, label: string}> */
    public static function options(): array
    {
        return [
            ['value' => null, 'label' => self::label(null)],
            ...array_map(fn (int $m) => ['value' => $m, 'label' => self::label($m)], self::PRESETS),
        ];
    }
}
