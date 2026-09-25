<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * System data only, safe for production and idempotent (re-running never
 * duplicates or overwrites admin edits): permissions, roles and their
 * permissions, default settings, lead statuses / sources / lost reasons,
 * follow-up types, meeting types and call dispositions.
 *
 * It creates no users, no business records, no integrations and no
 * credentials. The first account is created with crm:create-super-admin.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CoreSeeder::class);

        $this->command?->info('System data seeded. Create the first Super Admin with: php artisan crm:create-super-admin');
    }
}
