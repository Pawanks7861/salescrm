<?php

use App\Enums\AuditAction;
use App\Http\Controllers\NotificationController;
use App\Models\Role;
use App\Models\User;
use App\Support\Navigation;
use App\Support\Permissions;
use App\Support\SettingDefinitions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| The telephony / calling module was removed. Nothing of it may remain
| reachable, while lead phone numbers and historical records stay intact.
*/

const REMOVED_CALL_TABLES = ['calls', 'call_events', 'call_recordings', 'call_dispositions', 'telephony_integrations', 'telephony_numbers', 'telephony_users'];

beforeEach(function () {
    $this->org = salesOrg();
});

test('no calling or telephony route is registered', function () {
    $routes = collect(Route::getRoutes()->getRoutes());

    expect($routes->map(fn ($r) => $r->uri())->filter(fn ($uri) => preg_match('#(^|/)(calls|telephony)(/|$)#', $uri))->values()->all())->toBe([])
        ->and($routes->map(fn ($r) => (string) $r->getName())->filter(fn ($name) => Str::startsWith($name, ['calls.', 'telephony.', 'admin.integrations.telephony']))->values()->all())->toBe([]);

    foreach (['/calls', '/admin/integrations/telephony', '/telephony/config'] as $url) {
        $this->actingAs($this->org->super)->get($url)->assertNotFound();
    }
    $this->postJson('/webhooks/telephony/exotel/status')->assertNotFound();
});

test('no telephony command or scheduled task remains', function () {
    expect(collect(array_keys(Artisan::all()))->filter(fn ($name) => str_starts_with($name, 'telephony:'))->all())->toBe([]);

    Artisan::call('schedule:list');
    expect(Artisan::output())->not->toContain('telephony');
});

test('telephony tables are gone and lead phone fields are kept', function () {
    foreach (REMOVED_CALL_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} still exists");
    }

    expect(Schema::hasColumns('leads', ['phone', 'alternate_phone', 'normalized_phone']))->toBeTrue();
});

test('no call permission, telephony setting, audit action or navigation item is offered', function () {
    expect(collect(Permissions::names())->filter(fn ($p) => str_starts_with($p, 'call.'))->all())->toBe([])
        ->and(DB::table('permissions')->where('name', 'like', 'call.%')->count())->toBe(0)
        ->and(collect(array_keys(SettingDefinitions::all()))->filter(fn ($k) => str_starts_with($k, 'telephony.'))->all())->toBe([])
        ->and(DB::table('settings')->where('key', 'like', 'telephony.%')->count())->toBe(0)
        ->and(collect(AuditAction::cases())->filter(fn ($a) => str_starts_with($a->value, 'CALL_') || str_starts_with($a->value, 'TELEPHONY_'))->all())->toBe([]);

    foreach ([$this->org->super, $this->org->admin, $this->org->rahul] as $user) {
        expect(json_encode(Navigation::for($user)))->not->toContain('/calls')->not->toContain('telephony');
    }
});

test('historical call audit rows and legacy call notifications still display', function () {
    DB::table('audit_logs')->insert([
        'user_id' => $this->org->rahul->id, 'action' => 'CALL_STARTED', 'module' => 'calls',
        'description' => 'Legacy outbound call', 'created_at' => now(),
    ]);
    $this->actingAs($this->org->super)->get('/admin/audit-logs?action=CALL_STARTED')->assertOk()->assertSee('Legacy outbound call');

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\Legacy',
        'notifiable_type' => $this->org->rahul->getMorphClass(), 'notifiable_id' => $this->org->rahul->id,
        'data' => json_encode(['call_id' => 999, 'message' => 'Legacy call notice']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $row = $this->actingAs($this->org->rahul)->get('/notifications')->assertOk()->inertiaProps('notifications.data.0');
    expect($row['stale'])->toBeTrue()
        ->and($row['target'])->toBeNull()
        ->and($row['message'])->toBe(NotificationController::STALE_MESSAGE);
});

test('the removal migration clears leftover call permissions, telephony settings and CALL sequences only', function () {
    $migration = require database_path('migrations/2026_10_01_100000_remove_telephony_module.php');

    $migration->down();
    expect(Schema::hasTable('calls'))->toBeTrue();

    $permissionId = DB::table('permissions')->insertGetId(['name' => 'call.make', 'module' => 'Calls', 'label' => 'Make calls', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('role_permissions')->insert(['role_id' => Role::where('slug', 'sales_executive')->value('id'), 'permission_id' => $permissionId]);
    DB::table('user_permissions')->insert(['user_id' => $this->org->rahul->id, 'permission_id' => $permissionId, 'type' => 'grant', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('settings')->insert(['group' => 'telephony', 'key' => 'telephony.enabled', 'value' => '1', 'type' => 'boolean', 'created_at' => now(), 'updated_at' => now()]);
    foreach (['C:CALL', 'M:MT', 'LD'] as $prefix) {
        DB::table('number_sequences')->insert(['prefix' => $prefix, 'period' => '2026', 'last_value' => 7, 'created_at' => now(), 'updated_at' => now()]);
    }
    $leads = DB::table('leads')->count();
    $settings = DB::table('settings')->count();

    $migration->up();

    foreach (REMOVED_CALL_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(DB::table('permissions')->where('id', $permissionId)->exists())->toBeFalse()
        ->and(DB::table('role_permissions')->where('permission_id', $permissionId)->exists())->toBeFalse()
        ->and(DB::table('user_permissions')->where('permission_id', $permissionId)->exists())->toBeFalse()
        ->and(DB::table('settings')->count())->toBe($settings - 1)
        ->and(DB::table('number_sequences')->orderBy('prefix')->pluck('prefix')->all())->toBe(['LD', 'M:MT'])
        ->and(DB::table('leads')->count())->toBe($leads)
        ->and(User::find($this->org->rahul->id)->hasPermission('call.make'))->toBeFalse();
});
