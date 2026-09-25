<?php

namespace App\Support;

use App\Services\SettingService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Timezone helper. The database stores UTC (config app.timezone); users enter
 * and read times in the CRM timezone (setting general.timezone). All
 * conversion goes through Carbon — never manual offset arithmetic.
 */
final class CrmTime
{
    public const DISPLAY_FORMAT = 'M j, g:i A';

    public static function tz(): string
    {
        $tz = (string) app(SettingService::class)->get('general.timezone', 'UTC');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }

    /**
     * Converts a wall-clock date + time in `$tz` to a UTC instant.
     * Returns null when the input is not a real local time.
     */
    public static function toUtc(string $date, string $time, ?string $tz = null): ?CarbonImmutable
    {
        $tz ??= self::tz();
        $local = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", $tz);

        if (! $local || $local->format('Y-m-d H:i') !== "{$date} {$time}") {
            return null;
        }

        return $local->utc();
    }

    /** Start of the current CRM-local day, as UTC. */
    public static function startOfToday(): CarbonImmutable
    {
        return CarbonImmutable::now(self::tz())->startOfDay()->utc();
    }

    /** End of the current CRM-local day, as UTC. */
    public static function endOfToday(): CarbonImmutable
    {
        return CarbonImmutable::now(self::tz())->endOfDay()->utc();
    }

    /** Start of a CRM-local calendar date (Y-m-d), as UTC. */
    public static function startOfDate(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, self::tz())->startOfDay()->utc();
    }

    /** End of a CRM-local calendar date (Y-m-d), as UTC. */
    public static function endOfDate(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, self::tz())->endOfDay()->utc();
    }

    public static function local(CarbonInterface $at, ?string $tz = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone($tz ?? self::tz());
    }

    public static function format(?CarbonInterface $at, string $format = self::DISPLAY_FORMAT): string
    {
        return $at ? self::local($at)->format($format) : '';
    }
}
