<?php

use App\Models\Lead;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\FollowupDemoSeeder;
use Database\Seeders\LeadDemoSeeder;
use Database\Seeders\MeetingDemoSeeder;
use Database\Seeders\ReportingDemoSeeder;

/*
| Phase 8 §7 / §78: demo data (shared "Password@123" accounts, sample leads)
| can never be created outside local, even when a seeder is called directly.
*/

function runSeeder(string $class): void
{
    (new $class)->setContainer(app())->__invoke();
}

test('every demo seeder refuses to run in production', function (string $seeder) {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => runSeeder($seeder))->toThrow(RuntimeException::class, 'local-only');
    expect(User::where('email', 'like', '%@salescrm.local')->exists())->toBeFalse()
        ->and(Lead::withTrashed()->exists())->toBeFalse();
})->with([
    DemoSeeder::class,
    LeadDemoSeeder::class,
    FollowupDemoSeeder::class,
    MeetingDemoSeeder::class,
    ReportingDemoSeeder::class,
]);

test('demo seeders also refuse staging-like environments', function () {
    app()->detectEnvironment(fn () => 'staging');

    expect(fn () => runSeeder(DemoSeeder::class))->toThrow(RuntimeException::class);
});

test('in the test environment demo seeders skip without creating data', function () {
    runSeeder(DemoSeeder::class);

    expect(User::whereIn('email', ['rahul@salescrm.local', 'priya@salescrm.local', 'manager@salescrm.local', 'admin@salescrm.local'])->exists())->toBeFalse();
});

test('production seeding creates system data only, with no users and no demo data', function () {
    app()->detectEnvironment(fn () => 'production');

    runSeeder(DatabaseSeeder::class);
    runSeeder(DatabaseSeeder::class);

    expect(User::count())->toBe(0)
        ->and(Lead::withTrashed()->count())->toBe(0)
        ->and(Role::where('is_system', true)->count())->toBe(4)
        ->and(Setting::count())->toBeGreaterThan(0);
});
