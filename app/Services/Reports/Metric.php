<?php

namespace App\Services\Reports;

/**
 * Small, pure helpers for derived numbers. Every rate / change shown in the
 * UI is computed here (server side) so Vue only formats.
 */
final class Metric
{
    /** Percentage with one decimal; null (rendered "N/A") when the denominator is 0. */
    public static function rate(int|float|null $numerator, int|float|null $denominator): ?float
    {
        if (! $denominator) {
            return null;
        }

        return round(((float) $numerator / (float) $denominator) * 100, 1);
    }

    /** Relative change vs previous period; null ("N/A") when previous is 0 or unknown. */
    public static function change(int|float|null $current, int|float|null $previous): ?float
    {
        if ($previous === null || (float) $previous === 0.0 || $current === null) {
            return null;
        }

        return round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 1);
    }

    public static function avg(int|float|null $sum, int|float|null $count): ?float
    {
        return $count ? round((float) $sum / (float) $count, 1) : null;
    }

    public static function int(mixed $value): int
    {
        return (int) ($value ?? 0);
    }

    public static function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    /**
     * A KPI tile. `format`: number | percent | currency | duration | text.
     * `snapshot` KPIs describe "now" and are never compared.
     */
    public static function kpi(string $key, string $label, mixed $value, string $format = 'number', ?string $hint = null, mixed $previous = null, bool $snapshot = false, ?string $link = null, bool $higherIsBetter = true): array
    {
        $numeric = is_int($value) || is_float($value);

        return array_filter([
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'format' => $format,
            'hint' => $hint,
            'snapshot' => $snapshot ?: null,
            'previous' => $snapshot ? null : $previous,
            'change' => ! $snapshot && $numeric && $previous !== null
                ? ($format === 'percent' ? ($value === null || $previous === null ? null : round((float) $value - (float) $previous, 1)) : self::change($value, $previous))
                : null,
            'change_unit' => $format === 'percent' ? 'pts' : '%',
            'higher_is_better' => $higherIsBetter,
            'link' => $link,
        ], fn ($v, $k) => $v !== null || in_array($k, ['value', 'change'], true), ARRAY_FILTER_USE_BOTH);
    }
}
