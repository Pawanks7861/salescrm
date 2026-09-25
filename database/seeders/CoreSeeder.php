<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Reference data every environment needs (also used by the test suite). */
class CoreSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            SettingSeeder::class,
            LeadReferenceSeeder::class,
            FollowupReferenceSeeder::class,
            MeetingReferenceSeeder::class,
            TelephonyReferenceSeeder::class,
        ]);
    }
}
