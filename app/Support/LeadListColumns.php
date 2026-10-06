<?php

namespace App\Support;

/**
 * Column order for the leads list. The checkbox stays first and is not part
 * of this list. A saved order is per user; columns they cannot see are left
 * out, and any new column is appended so an old save never hides it.
 */
final class LeadListColumns
{
    /** @var list<string> */
    public const KEYS = [
        'lead',
        'contact',
        'created_at',
        'status',
        'priority',
        'source',
        'owner',
        'batches',
        'city',
        'value',
        'updated_at',
    ];

    /** @return list<string> */
    public static function available(bool $batches, bool $value): array
    {
        return array_values(array_filter(
            self::KEYS,
            fn (string $key) => match ($key) {
                'batches' => $batches,
                'value' => $value,
                default => true,
            },
        ));
    }

    /**
     * @param  list<string>|null  $saved
     * @return list<string>
     */
    public static function resolve(?array $saved, bool $batches, bool $value): array
    {
        $available = self::available($batches, $value);
        $kept = array_values(array_intersect($saved ?? [], $available));

        if ($kept === []) {
            return $available;
        }

        return [...$kept, ...array_values(array_diff($available, $kept))];
    }
}
