<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

/** Lead age = whole days since created_at. */
enum LeadAgeBucket: string
{
    case Fresh = '0-1';
    case Recent = '2-3';
    case Week = '4-7';
    case Fortnight = '8-15';
    case Old = '15+';

    public function label(): string
    {
        return $this->value.' days';
    }

    /** @return array{0: int, 1: ?int} inclusive [min, max] age in days */
    public function range(): array
    {
        return match ($this) {
            self::Fresh => [0, 1],
            self::Recent => [2, 3],
            self::Week => [4, 7],
            self::Fortnight => [8, 15],
            self::Old => [16, null],
        };
    }

    /** @return array{0: ?Carbon, 1: Carbon} created_at bounds: (after, onOrBefore] */
    public function createdBetween(?Carbon $now = null): array
    {
        $now ??= now();
        [$min, $max] = $this->range();

        return [$max === null ? null : $now->copy()->subDays($max + 1), $now->copy()->subDays($min)];
    }

    public static function forDays(int $days): self
    {
        foreach (self::cases() as $bucket) {
            [$min, $max] = $bucket->range();
            if ($days >= $min && ($max === null || $days <= $max)) {
                return $bucket;
            }
        }

        return self::Old;
    }

    public static function options(): array
    {
        return array_map(fn (self $b) => ['value' => $b->value, 'label' => $b->label()], self::cases());
    }
}
