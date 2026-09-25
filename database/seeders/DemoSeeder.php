<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\Concerns\LocalDemoOnly;
use Illuminate\Database\Seeder;

/** Local-only demo organisation. All demo accounts use the password "Password@123". */
class DemoSeeder extends Seeder
{
    use LocalDemoOnly;

    public function run(): void
    {
        if (! $this->demoSeedingAllowed()) {
            return;
        }

        $roles = Role::pluck('id', 'slug');

        $admin = $this->user('Anita Admin', 'admin@salescrm.local', $roles['admin'], 'Operations Head');
        $manager = $this->user('Mehul Manager', 'manager@salescrm.local', $roles['sales_manager'], 'Sales Manager');

        $team = Team::firstOrCreate(['name' => 'Ahmedabad Team'], ['manager_id' => $manager->id, 'is_active' => true]);
        Team::firstOrCreate(['name' => 'Mumbai Team'], ['is_active' => true]);

        $rahul = $this->user('Rahul Sharma', 'rahul@salescrm.local', $roles['sales_executive'], 'Sales Executive', $team, $manager);
        $priya = $this->user('Priya Patel', 'priya@salescrm.local', $roles['sales_executive'], 'Sales Executive', $team, $manager);

        $team->members()->syncWithoutDetaching([$manager->id, $rahul->id, $priya->id]);
        $manager->team_id = $team->id;
        $manager->save();

        $this->command?->info('Demo users: admin@, manager@, rahul@, priya@salescrm.local / Password@123');

        $this->call(LeadDemoSeeder::class);
        $this->call(FollowupDemoSeeder::class);
        $this->call(MeetingDemoSeeder::class);
        $this->call(ReportingDemoSeeder::class);
    }

    private function user(string $name, string $email, int $roleId, string $designation, ?Team $team = null, ?User $manager = null): User
    {
        $user = User::firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->fill(['name' => $name, 'password' => 'Password@123', 'designation' => $designation]);
            $user->role_id = $roleId;
            $user->team_id = $team?->id;
            $user->manager_id = $manager?->id;
            $user->is_active = true;
            $user->email_verified_at = now();
            $user->save();
        }

        return $user;
    }
}
