<?php

use App\Models\FacebookIntegration;
use App\Models\Lead;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Leads\LeadAssignedNotification;
use App\Services\Leads\LeadNumberService;
use App\Services\Maintenance\DemoDataCleaner;
use App\Services\Reports\ReportRegistry;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/*
| Pre-live bootstrap: ProductionSeeder seeds system data only, the first
| Super Admin is created interactively, demo data can be cleared safely,
| crm:production-data-check verifies the result, and every screen works
| with zero business records.
*/

const BOOTSTRAP_PASSWORD = 'Str0ng!Launch#Pass';

function seedProduction(): void
{
    (new ProductionSeeder)->setContainer(app())->__invoke();
}

function systemCounts(): array
{
    return collect(['roles', 'permissions', 'role_permissions', 'lead_statuses', 'lead_sources', 'lost_reasons', 'followup_types', 'meeting_types', 'settings'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
}

describe('ProductionSeeder', function () {
    test('seeds system data only: no users, leads, meetings, follow-ups or Meta enquiries', function () {
        app()->detectEnvironment(fn () => 'production');
        $before = systemCounts();

        seedProduction();
        seedProduction();

        expect(systemCounts())->toBe($before)
            ->and(Role::where('is_system', true)->count())->toBe(4)
            ->and(Permission::count())->toBeGreaterThan(0)
            ->and(Setting::count())->toBeGreaterThan(0);

        foreach (['users', 'leads', 'lead_enquiries', 'meetings', 'followups', 'teams', 'campaigns', 'facebook_integrations', 'notifications'] as $table) {
            expect(DB::table($table)->count())->toBe(0, "{$table} must be empty");
        }
    });

    test('recreates missing system rows without touching admin edits', function () {
        DB::table('lead_sources')->where('slug', 'website')->update(['name' => 'Company Website']);
        $missing = DB::table('meeting_types')->orderByDesc('id')->first();
        DB::table('meeting_types')->where('id', $missing->id)->delete();

        seedProduction();

        expect(DB::table('lead_sources')->where('slug', 'website')->value('name'))->toBe('Company Website')
            ->and(DB::table('meeting_types')->where('slug', $missing->slug)->exists())->toBeTrue();
    });

    test('DatabaseSeeder outside local never creates a user or demo data', function (string $env) {
        app()->detectEnvironment(fn () => $env);
        (new DatabaseSeeder)->setContainer(app())->__invoke();

        expect(User::withTrashed()->count())->toBe(0)->and(Lead::withTrashed()->count())->toBe(0);
    })->with(['production', 'staging', 'testing']);

    test('demo seeders cannot run in production', function () {
        app()->detectEnvironment(fn () => 'production');

        expect(fn () => (new DemoSeeder)->setContainer(app())->__invoke())->toThrow(RuntimeException::class, 'local-only');
        expect(User::count())->toBe(0);
    });
});

describe('Super Admin bootstrap', function () {
    test('creates one active Super Admin and never prints or logs the password', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });

        $this->artisan('crm:create-super-admin')
            ->expectsQuestion('Full name', 'Client Owner')
            ->expectsQuestion('Email', 'owner@client.test')
            ->expectsQuestion('Password (min 12 characters, upper/lower case, number and symbol)', BOOTSTRAP_PASSWORD)
            ->expectsQuestion('Confirm password', BOOTSTRAP_PASSWORD)
            ->doesntExpectOutputToContain(BOOTSTRAP_PASSWORD)
            ->assertSuccessful();

        $user = User::sole();
        expect($user->isSuperAdmin())->toBeTrue()
            ->and($user->is_active)->toBeTrue()
            ->and(implode("\n", $logged))->not->toContain(BOOTSTRAP_PASSWORD)
            ->and(DB::table('audit_logs')->get()->toJson())->not->toContain(BOOTSTRAP_PASSWORD);
    });

    test('crm:reset-super-admin changes email and password, revokes sessions, never echoes the password', function () {
        $admin = User::factory()->superAdmin()->create(['email' => 'superadmin@salescrm.local', 'remember_token' => 'old-token']);
        DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);
        $new = 'N3w!Production#Key';

        $this->artisan('crm:reset-super-admin')
            ->expectsConfirmation('Change the name, email and password of superadmin@salescrm.local? All of its sessions will be signed out.', 'yes')
            ->expectsQuestion('Full name', 'Client Owner')
            ->expectsQuestion('Email', 'Owner@Client.test')
            ->expectsQuestion('New password (min 12 characters, upper/lower case, number and symbol)', $new)
            ->expectsQuestion('Confirm new password', $new)
            ->expectsOutputToContain('Super Admin updated: owner@client.test')
            ->doesntExpectOutputToContain($new)
            ->assertSuccessful();

        $admin->refresh();
        expect($admin->email)->toBe('owner@client.test')
            ->and(Hash::check($new, $admin->password))->toBeTrue()
            ->and($admin->remember_token)->not->toBe('old-token')
            ->and(DB::table('sessions')->where('user_id', $admin->id)->exists())->toBeFalse()
            ->and(DB::table('audit_logs')->get()->toJson())->not->toContain($new);
    });

    test('crm:reset-super-admin rejects weak passwords and changes nothing', function () {
        $admin = User::factory()->superAdmin()->create(['email' => 'owner@client.test']);
        $hash = $admin->password;

        $this->artisan('crm:reset-super-admin')
            ->expectsConfirmation('Change the name, email and password of owner@client.test? All of its sessions will be signed out.', 'yes')
            ->expectsQuestion('Full name', 'Owner')
            ->expectsQuestion('Email', 'owner@client.test')
            ->expectsQuestion('New password (min 12 characters, upper/lower case, number and symbol)', 'password')
            ->expectsQuestion('Confirm new password', 'password')
            ->assertFailed();

        expect($admin->fresh()->password)->toBe($hash);
    });

    test('the reset command has no password option', function () {
        expect(Artisan::all()['crm:reset-super-admin']->getDefinition()->hasOption('password'))->toBeFalse();
    });
});

describe('crm:clear-demo-data', function () {
    function demoOrganisation(): object
    {
        pushConfigure();
        Storage::fake('local');
        $super = User::factory()->superAdmin()->create(['email' => 'superadmin@salescrm.local']);
        $rahul = User::factory()->salesExecutive()->create(['email' => 'rahul@salescrm.local', 'name' => 'Rahul Sharma']);
        $admin = User::factory()->admin()->create(['email' => 'admin@salescrm.local']);
        $real = User::factory()->salesExecutive()->create(['email' => 'staff@client.test']);
        $team = Team::create(['name' => 'Ahmedabad Team', 'is_active' => true]);
        $rahul->forceFill(['team_id' => $team->id, 'manager_id' => $admin->id])->save();

        $lead = Lead::factory()->assignedTo($rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
        Lead::factory()->assignedTo($rahul)->create(['duplicate_of_id' => $lead->id]);
        scheduleFollowup($lead, $rahul);
        scheduleMeeting($lead, $rahul);
        metaSetup();
        pushSubscribe($rahul, 'rahul-laptop');
        $rahul->notify(new LeadAssignedNotification($lead));
        Storage::disk('local')->put('leads/'.$lead->id.'/quote.pdf', 'demo');
        Storage::disk('local')->put('report-exports/old.csv', 'demo');
        DB::table('number_sequences')->insert(['prefix' => 'LD', 'period' => '2026', 'last_value' => 34, 'created_at' => now(), 'updated_at' => now()]);

        return (object) compact('super', 'rahul', 'admin', 'real', 'lead');
    }

    test('refuses in production without --force-production', function () {
        $org = demoOrganisation();
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('crm:clear-demo-data')->expectsOutputToContain('Refusing to run in production')->assertFailed();

        expect(Lead::count())->toBe(2)->and(User::count())->toBe(4);
    });

    test('in production --force-production still requires the typed phrase', function () {
        demoOrganisation();
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('crm:clear-demo-data', ['--force-production' => true])
            ->expectsQuestion('Type DELETE-DEMO-DATA to continue', 'yes')
            ->expectsOutput('Nothing deleted.')
            ->assertFailed();

        expect(Lead::count())->toBe(2);
    });

    test('the wrong phrase deletes nothing', function () {
        demoOrganisation();

        $this->artisan('crm:clear-demo-data')->expectsQuestion('Type DELETE-DEMO-DATA to continue', 'delete')->assertFailed();

        expect(Lead::count())->toBe(2)->and(DB::table('meetings')->count())->toBe(1);
    });

    test('removes every operational record, demo users and files; keeps the Super Admin, other staff and system data', function () {
        $org = demoOrganisation();
        $system = systemCounts();

        $this->artisan('crm:clear-demo-data')->expectsQuestion('Type DELETE-DEMO-DATA to continue', 'DELETE-DEMO-DATA')->assertSuccessful();

        foreach (DemoDataCleaner::OPERATIONAL_TABLES as $table) {
            expect(DB::table($table)->count())->toBe(0, "{$table} must be empty");
        }
        expect(User::withTrashed()->pluck('email')->sort()->values()->all())->toBe(['staff@client.test', 'superadmin@salescrm.local'])
            ->and(systemCounts())->toBe($system)
            ->and(Storage::disk('local')->allFiles())->toBe([])
            ->and(FacebookIntegration::withTrashed()->count())->toBe(0);
    });

    test('--all-users keeps only Super Admin accounts', function () {
        demoOrganisation();

        $this->artisan('crm:clear-demo-data', ['--all-users' => true])->expectsQuestion('Type DELETE-DEMO-DATA to continue', 'DELETE-DEMO-DATA')->assertSuccessful();

        expect(User::withTrashed()->count())->toBe(1)->and(User::sole()->isSuperAdmin())->toBeTrue();
    });

    test('lead numbers start again at 000001 after cleanup', function () {
        demoOrganisation();
        $this->artisan('crm:clear-demo-data')->expectsQuestion('Type DELETE-DEMO-DATA to continue', 'DELETE-DEMO-DATA')->assertSuccessful();

        expect(app(LeadNumberService::class)->next())->toEndWith('-000001');
    });
});

describe('crm:production-data-check', function () {
    test('passes with system data and one real Super Admin, printing counts only', function () {
        User::factory()->superAdmin()->create(['email' => 'owner@client.test', 'name' => 'Client Owner']);

        $this->artisan('crm:production-data-check')
            ->expectsOutputToContain('Clean: system configuration and Super Admin only.')
            ->doesntExpectOutputToContain('owner@client.test')
            ->doesntExpectOutputToContain('Client Owner')
            ->assertSuccessful();
    });

    test('fails on demo accounts, demo leads and any business record', function (string $case) {
        User::factory()->superAdmin()->create(['email' => 'owner@client.test']);
        match ($case) {
            'demo account' => User::factory()->create(['email' => 'priya@salescrm.local']),
            'default super admin email' => User::query()->update(['email' => 'superadmin@salescrm.local']),
            'demo lead' => Lead::factory()->create(['first_name' => 'Suresh', 'last_name' => 'Nair']),
            'any lead' => Lead::factory()->create(),
            'no super admin' => User::query()->forceDelete(),
        };

        $this->artisan('crm:production-data-check')->assertFailed();
    })->with(['demo account', 'default super admin email', 'demo lead', 'any lead', 'no super admin']);

    test('--live allows business records but still fails on demo markers', function () {
        $owner = User::factory()->superAdmin()->create(['email' => 'owner@client.test']);
        Lead::factory()->create(['first_name' => 'Real', 'last_name' => 'Customer']);
        User::factory()->salesExecutive()->create(['email' => 'staff@client.test']);

        $this->artisan('crm:production-data-check', ['--live' => true])->assertSuccessful();

        Lead::factory()->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
        $this->artisan('crm:production-data-check', ['--live' => true])->assertFailed();
    });
});

describe('empty CRM', function () {
    beforeEach(function () {
        $this->owner = User::factory()->superAdmin()->create(['email' => 'owner@client.test']);
    });

    test('every main screen renders with zero business records and no NaN / Infinity', function (string $uri) {
        $response = $this->actingAs($this->owner)->get($uri)->assertOk();

        $props = json_encode($response->viewData('page')['props']);

        expect($props)->not->toBeFalse()->not->toContain('NaN')->not->toContain('Infinity');
    })->with([
        'dashboard' => '/dashboard',
        'leads' => '/leads',
        'pipeline' => '/leads/pipeline',
        'follow-ups' => '/follow-ups',
        'meetings' => '/meetings',
        'calendar' => '/calendar',
        'notifications' => '/notifications',
        'reports' => '/reports',
        'users' => '/admin/users',
        'facebook integration' => '/admin/integrations/facebook',
        'audit log' => '/admin/audit-logs',
    ]);

    test('every report renders with zero data and no NaN / Infinity', function () {
        foreach (array_keys(ReportRegistry::REPORTS) as $slug) {
            $content = $this->actingAs($this->owner)->get("/reports/{$slug}")->assertOk()->getContent();

            expect($content)->not->toContain('NaN', "report {$slug}")->not->toContain('Infinity', "report {$slug}");
        }
    });

    test('the calendar feed is empty, not an error', function () {
        $this->actingAs($this->owner)
            ->getJson('/calendar/events?start='.now()->startOfMonth()->toDateString().'&end='.now()->endOfMonth()->toDateString())
            ->assertOk();
    });
});
