<?php

namespace Database\Seeders;

use App\Services\PermissionRegistrar;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /** Permissions created during this seeding run; RoleSeeder grants them to existing system roles. */
    public static array $created = [];

    public function run(PermissionRegistrar $registrar): void
    {
        self::$created = $registrar->syncCatalogue();
    }
}
