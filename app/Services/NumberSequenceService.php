<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Row-locked counters in `number_sequences` (never MAX(id)+1), so concurrent
 * requests cannot produce the same number. One counter per (key, period).
 */
class NumberSequenceService
{
    public function __construct(private readonly SettingService $settings) {}

    /** Year in the CRM timezone, used as the default reset period. */
    public function currentYear(): string
    {
        return now()->setTimezone((string) $this->settings->get('general.timezone', config('app.timezone')))->format('Y');
    }

    public function next(string $key, string $period): int
    {
        return DB::transaction(function () use ($key, $period) {
            DB::table('number_sequences')->insertOrIgnore([
                'prefix' => $key,
                'period' => $period,
                'last_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('number_sequences')
                ->where('prefix', $key)
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            $next = (int) $row->last_value + 1;

            DB::table('number_sequences')
                ->where('id', $row->id)
                ->update(['last_value' => $next, 'updated_at' => now()]);

            return $next;
        });
    }

    /** Settings prefix sanitised to upper-case alphanumerics. */
    public function prefix(string $settingKey, string $fallback): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $this->settings->get($settingKey, $fallback)) ?: $fallback);
    }
}
