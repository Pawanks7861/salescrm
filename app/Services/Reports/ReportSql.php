<?php

namespace App\Services\Reports;

use App\Support\CrmTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Driver-aware SQL fragments for MySQL (production) and SQLite (tests).
 *
 * CRM-local bucketing shifts UTC timestamps by the CRM timezone's UTC offset
 * at the end of the report period. MySQL time-zone tables are not required.
 * For zones with daylight saving the offset may differ by one hour for rows
 * on the other side of a DST switch inside the same period (documented).
 */
final class ReportSql
{
    /** Values plucked for median / percentile calculation are capped at this. */
    public const PERCENTILE_CAP = 50000;

    public static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    public static function offsetSeconds(?CarbonImmutable $at = null): int
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone(CrmTime::tz())->utcOffset() * 60;
    }

    /** CRM-local calendar date (Y-m-d) of a UTC timestamp column. */
    public static function localDate(string $column, int $offset): string
    {
        return self::driver() === 'sqlite'
            ? "date({$column}, '".self::signed($offset)." seconds')"
            : "DATE(DATE_ADD({$column}, INTERVAL {$offset} SECOND))";
    }

    /** Bucket start (Y-m-d) for day / week (Monday) / month granularity. */
    public static function bucket(string $column, string $granularity, int $offset): string
    {
        $local = self::localDate($column, $offset);

        if (self::driver() === 'sqlite') {
            return match ($granularity) {
                'week' => "date({$local}, '-6 days', 'weekday 1')",
                'month' => "strftime('%Y-%m-01', {$local})",
                default => $local,
            };
        }

        return match ($granularity) {
            'week' => "DATE_SUB({$local}, INTERVAL WEEKDAY({$local}) DAY)",
            'month' => "DATE_FORMAT({$local}, '%Y-%m-01')",
            default => $local,
        };
    }

    /** Whole seconds from `$start` to `$end` (both SQL expressions). */
    public static function diffSeconds(string $start, string $end): string
    {
        return self::driver() === 'sqlite'
            ? "(CAST(strftime('%s', {$end}) AS INTEGER) - CAST(strftime('%s', {$start}) AS INTEGER))"
            : "TIMESTAMPDIFF(SECOND, {$start}, {$end})";
    }

    /** Rounds a non-negative numeric expression down to an integer. */
    public static function floor(string $expression): string
    {
        return self::driver() === 'sqlite' ? "CAST(({$expression}) AS INTEGER)" : "FLOOR({$expression})";
    }

    /** Earliest non-null of several timestamp expressions. */
    public static function earliest(string ...$expressions): string
    {
        $sentinel = "'9999-12-31 00:00:00'";
        $wrapped = array_map(fn ($e) => "COALESCE({$e}, {$sentinel})", $expressions);
        $fn = self::driver() === 'sqlite' ? 'MIN' : 'LEAST';
        $inner = count($wrapped) === 1 ? $wrapped[0] : "{$fn}(".implode(', ', $wrapped).')';

        return "NULLIF({$inner}, {$sentinel})";
    }

    /** Latest non-null of several timestamp expressions. */
    public static function latest(string ...$expressions): string
    {
        $sentinel = "'1000-01-01 00:00:00'";
        $wrapped = array_map(fn ($e) => "COALESCE({$e}, {$sentinel})", $expressions);
        $fn = self::driver() === 'sqlite' ? 'MAX' : 'GREATEST';
        $inner = count($wrapped) === 1 ? $wrapped[0] : "{$fn}(".implode(', ', $wrapped).')';

        return "NULLIF({$inner}, {$sentinel})";
    }

    /** SQL timestamp literal for a UTC instant (safe: generated, not user input). */
    public static function at(CarbonImmutable $at): string
    {
        return "'".$at->utc()->format('Y-m-d H:i:s')."'";
    }

    /**
     * Percentiles (0–100) of a numeric expression, nearest-rank method.
     * Values are streamed ordered from SQL; when more than PERCENTILE_CAP rows
     * match, null is returned rather than loading an unbounded set.
     *
     * @param  array<int>  $percentiles
     * @return array<int, ?float>
     */
    public static function percentiles(EloquentBuilder|Builder $query, string $expression, array $percentiles = [50]): array
    {
        $values = $query instanceof EloquentBuilder ? (clone $query)->toBase() : clone $query;
        $values->columns = null;
        $values->bindings['select'] = [];
        $values->orders = null;
        $values = DB::query()->fromSub($values->selectRaw("{$expression} AS v"), 'p')->whereNotNull('v');

        $count = (clone $values)->count();
        if ($count === 0 || $count > self::PERCENTILE_CAP) {
            return array_fill_keys($percentiles, null);
        }

        $sorted = $values->orderBy('v')->pluck('v')->map(fn ($v) => (float) $v)->all();
        $result = [];
        foreach ($percentiles as $p) {
            $rank = max(1, (int) ceil($p / 100 * $count));
            $result[$p] = $sorted[$rank - 1];
        }

        return $result;
    }

    private static function signed(int $offset): string
    {
        return ($offset >= 0 ? '+' : '').$offset;
    }
}
