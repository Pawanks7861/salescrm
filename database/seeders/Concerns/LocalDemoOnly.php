<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * Demo data (demo users with the shared "Password@123", sample leads,
 * follow-ups, meetings) may only be created in the local environment. Any environment
 * other than local/testing is refused with an exception so that
 * `db:seed --class=DemoSeeder` cannot silently populate a real CRM.
 */
trait LocalDemoOnly
{
    protected function demoSeedingAllowed(): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        if (! app()->environment('testing')) {
            throw new RuntimeException('Demo seeders are local-only and refuse to run in the "'.app()->environment().'" environment.');
        }

        $this->command?->warn(class_basename(static::class).' is local-only; skipped.');

        return false;
    }
}
