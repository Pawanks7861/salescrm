<?php

namespace App\Support;

/**
 * Lead estimated value is temporarily hidden from the UI, props and exports
 * (config crm.features.lead_value, default off). The leads.estimated_value
 * column and its data are retained; nothing here writes to the database.
 */
final class LeadValue
{
    /** Report keys that only make sense next to a monetary lead value. */
    private const VALUE_KEYS = ['no_value', 'probability'];

    public static function enabled(): bool
    {
        return (bool) config('crm.features.lead_value', false);
    }

    /** @param  array<int, array>  $sections */
    public static function sections(array $sections): array
    {
        if (self::enabled()) {
            return $sections;
        }

        $out = [];
        foreach ($sections as $section) {
            $section = match ($section['type'] ?? null) {
                'kpis' => ($items = self::kpis($section['items'] ?? [])) === [] ? null : [...$section, 'items' => $items],
                'chart' => ($section['format'] ?? 'number') === 'currency' ? null : $section,
                'table' => self::table($section),
                default => $section,
            };
            if ($section !== null) {
                $out[] = $section;
            }
        }

        return $out;
    }

    /** @param  array<int, array>  $items */
    public static function kpis(array $items): array
    {
        if (self::enabled()) {
            return $items;
        }

        return array_values(array_filter($items, fn ($i) => ! self::hidden($i)));
    }

    /** Drops value columns and their cells from a table (or export table) payload. */
    public static function table(array $table): array
    {
        if (self::enabled()) {
            return $table;
        }

        $drop = array_column(array_filter($table['columns'] ?? [], fn ($c) => self::hidden($c)), 'key');
        if ($drop === []) {
            return $table;
        }

        $strip = fn ($row) => is_array($row) ? array_diff_key($row, array_flip($drop)) : $row;
        $table['columns'] = array_values(array_filter($table['columns'], fn ($c) => ! in_array($c['key'], $drop, true)));
        $rows = $table['rows'] ?? [];
        $table['rows'] = is_array($rows) ? array_map($strip, $rows) : (function () use ($rows, $strip) {
            foreach ($rows as $row) {
                yield $strip($row);
            }
        })();
        if (! empty($table['totals'])) {
            $table['totals'] = $strip($table['totals']);
        }

        return $table;
    }

    private static function hidden(array $metric): bool
    {
        return ($metric['format'] ?? null) === 'currency' || in_array($metric['key'] ?? null, self::VALUE_KEYS, true);
    }
}
