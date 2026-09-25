<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Every environment gets ProductionSeeder (system data only). Only
 * APP_ENV=local additionally gets a convenience Super Admin and the demo
 * organisation (DemoSeeder). Production, staging and testing never create
 * users or demo records here.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ProductionSeeder::class);

        if (! app()->environment('local')) {
            return;
        }

        $this->seedLocalSuperAdmin();
        $this->call(DemoSeeder::class);
    }

    /** Local development convenience only; every other environment uses crm:create-super-admin. */
    private function seedLocalSuperAdmin(): void
    {
        $email = env('SUPER_ADMIN_EMAIL') ?: 'superadmin@salescrm.local';

        if (User::withTrashed()->where('email', $email)->exists()) {
            return;
        }

        $password = env('SUPER_ADMIN_PASSWORD') ?: Str::password(16);

        $user = new User(['name' => 'Super Admin', 'email' => $email, 'password' => $password]);
        $user->role_id = Role::where('slug', User::SUPER_ADMIN_ROLE)->value('id');
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();

        $this->command?->warn("Local Super Admin created: {$email}");
        if (! env('SUPER_ADMIN_PASSWORD')) {
            $this->command?->warn("Generated password (shown once): {$password}");
        }
    }
}
