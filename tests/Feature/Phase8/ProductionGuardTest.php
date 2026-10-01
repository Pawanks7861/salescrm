<?php

use App\Console\Commands\CreateSuperAdmin;
use App\Console\Commands\ProductionCheck;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/*
| Phase 8 §5 / §8: app:production-check reports readiness without revealing
| secrets; crm:create-super-admin creates the first account interactively.
*/

function productionCheck(): array
{
    $code = Artisan::call('app:production-check');

    return [$code, Artisan::output()];
}

function productionReadyConfig(): void
{
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://crm.client.test',
        'session.secure' => true,
        'queue.default' => 'database',
        'cache.default' => 'file',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.client.test',
        'mail.from.address' => 'crm@client.test',
    ]);
    Cache::forever(ProductionCheck::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String());
}

test('the check fails the development defaults of the test environment', function () {
    config(['app.debug' => true, 'queue.default' => 'sync', 'mail.default' => 'log', 'app.url' => 'http://localhost']);

    [$code, $output] = productionCheck();

    expect($code)->toBe(1)
        ->and($output)->toContain('Debug mode is ON')
        ->toContain('QUEUE_CONNECTION=sync')
        ->toContain('APP_URL must start with https://')
        ->toContain('Not ready for production')
        ->not->toContain('Telephony');
});

test('a production-ready configuration passes the release-blocking rows', function () {
    productionReadyConfig();

    [, $output] = productionCheck();

    foreach (['APP_ENV', 'APP_DEBUG', 'APP_KEY', 'HTTPS (APP_URL)', 'Secure session cookie', 'Queue', 'Meta manual token form', 'Private file disk', 'Demo accounts', 'Scheduler'] as $check) {
        expect($output)->toMatch('/\|\s*'.preg_quote($check, '/').'\s*\|\s*PASS\s*\|/');
    }
});

test('the check never prints secret values, only whether they are configured', function () {
    $secrets = [
        'app.key' => 'base64:'.base64_encode(str_repeat('K', 32)),
        'meta.app_id' => 'META-APP-ID-MARKER-1',
        'meta.app_secret' => 'META-SECRET-MARKER-2',
        'meta.webhook_verify_token' => 'META-VERIFY-MARKER-3',
        'webpush.vapid.private_key' => 'VAPID-PRIVATE-MARKER-8',
        'database.connections.mysql.password' => 'DB-PASSWORD-MARKER-9',
        'mail.mailers.smtp.password' => 'SMTP-PASSWORD-MARKER-10',
    ];
    config($secrets);

    [, $output] = productionCheck();

    foreach ($secrets as $value) {
        expect($output)->not->toContain($value);
    }
    expect($output)->toContain('App ID, secret and verify token configured');
});

test('partially configured integrations fail instead of passing silently', function () {
    config(['meta.app_id' => 'x', 'meta.app_secret' => null, 'meta.webhook_verify_token' => null, 'meta.allow_manual_token' => true]);

    [$code, $output] = productionCheck();

    expect($code)->toBe(1)
        ->and($output)->toContain('Missing: META_APP_SECRET, META_WEBHOOK_VERIFY_TOKEN')
        ->toContain('META_ALLOW_MANUAL_TOKEN must be false');
});

test('demo accounts are reported as a failure', function () {
    User::factory()->create(['email' => 'rahul@salescrm.local']);

    [$code, $output] = productionCheck();

    expect($code)->toBe(1)->and($output)->toContain('demo/default account(s)');
});

test('crm:create-super-admin creates an audited, hashed Super Admin without echoing the password', function () {
    $password = 'Str0ng!Launch#Pass';

    $this->artisan('crm:create-super-admin')
        ->expectsQuestion('Full name', 'Client Owner')
        ->expectsQuestion('Email', 'Owner@Client.test')
        ->expectsQuestion('Password (min 12 characters, upper/lower case, number and symbol)', $password)
        ->expectsQuestion('Confirm password', $password)
        ->expectsOutput('Super Admin created: owner@client.test')
        ->doesntExpectOutputToContain($password)
        ->assertSuccessful();

    $user = User::where('email', 'owner@client.test')->sole();
    expect($user->role_id)->toBe(Role::where('slug', User::SUPER_ADMIN_ROLE)->value('id'))
        ->and($user->is_active)->toBeTrue()
        ->and($user->password)->not->toBe($password)
        ->and(Hash::check($password, $user->password))->toBeTrue();

    $audit = AuditLog::where('action', 'USER_CREATED')->where('entity_id', $user->id)->sole();
    expect($audit->route)->toBe('console')
        ->and(json_encode($audit->new_values_json))->not->toContain($password);
});

test('weak passwords, mismatches and duplicate emails are rejected', function (string $email, string $password, string $confirm, string $error) {
    User::factory()->create(['email' => 'taken@client.test']);

    $this->artisan('crm:create-super-admin')
        ->expectsQuestion('Full name', 'Someone')
        ->expectsQuestion('Email', $email)
        ->expectsQuestion('Password (min 12 characters, upper/lower case, number and symbol)', $password)
        ->expectsQuestion('Confirm password', $confirm)
        ->expectsOutputToContain($error)
        ->assertFailed();

    expect(User::where('email', $email)->where('name', 'Someone')->exists())->toBeFalse();
})->with([
    'too short' => ['a@client.test', 'Sh0rt!pw', 'Sh0rt!pw', 'at least 12 characters'],
    'no symbol' => ['b@client.test', 'NoSymbolPassw0rd', 'NoSymbolPassw0rd', 'symbol'],
    'mismatch' => ['c@client.test', 'Str0ng!Launch#Pass', 'Different!Pass99', 'confirmation does not match'],
    'duplicate email' => ['taken@client.test', 'Str0ng!Launch#Pass', 'Str0ng!Launch#Pass', 'already been taken'],
]);

test('the password can never be passed as a command-line option', function () {
    $definition = Artisan::all()['crm:create-super-admin']->getDefinition();

    expect($definition->hasOption('password'))->toBeFalse()
        ->and(CreateSuperAdmin::MIN_PASSWORD_LENGTH)->toBeGreaterThanOrEqual(12);
});

test('a second Super Admin is never created; changes go through crm:reset-super-admin', function () {
    User::factory()->superAdmin()->create();

    $this->artisan('crm:create-super-admin')
        ->expectsOutputToContain('A Super Admin already exists')
        ->expectsOutputToContain('crm:reset-super-admin')
        ->assertFailed();

    expect(User::count())->toBe(1);
});
