<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
| Phase 8 §6 / §52: no debug or development routes, every route cacheable,
| every non-public route behind authentication.
*/

function routeUris(): array
{
    return collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->all();
}

test('no debug, profiler or test-bypass routes are registered', function () {
    foreach (routeUris() as $uri) {
        expect($uri)->not->toMatch('/(^|\/)(_ignition|telescope|horizon|debugbar|clockwork|phpinfo|_debug|__clockwork)(\/|$)/');
    }
    expect(Route::has('storage.local'))->toBeFalse();
});

test('every route uses a controller so route:cache works', function () {
    $closures = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => $r->getAction('uses') instanceof Closure)
        ->map(fn ($r) => $r->uri())
        ->values()
        ->all();

    expect($closures)->toBe([]);
});

test('every route is authenticated except the documented public endpoints', function () {
    // Guest auth screens, uptime probe, signed/secret-checked provider callbacks, public branding assets.
    $public = [
        'login', 'forgot-password', 'reset-password', 'reset-password/*', 'health',
        'webhooks/meta/*', 'webhooks/telephony/*', 'branding/*', 'sanctum/csrf-cookie',
    ];

    $unexpected = collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => $m === 'auth' || str_starts_with($m, 'auth:')))
        ->map(fn ($r) => $r->uri())
        ->reject(fn ($uri) => $uri === '/' || collect($public)->contains(fn ($p) => fnmatch($p, $uri)))
        ->unique()
        ->values();

    expect($unexpected->all())->toBe([]);
});

test('the local simulator routes 404 in production', function () {
    app()->detectEnvironment(fn () => 'production');
    $org = salesOrg();
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->actingAs($org->rahul)->postJson('/telephony/fake/incoming', ['number' => '+919800000000'])->assertNotFound();
});

test('the meta test-lead command refuses production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('meta:test-lead', ['--setup' => true])->assertFailed();
});

test('the performance dataset generator refuses production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('crm:seed-performance', ['--leads' => 1])
        ->expectsOutputToContain('only runs in the local/testing environment')
        ->assertFailed();
});
