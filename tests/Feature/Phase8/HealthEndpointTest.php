<?php

use Illuminate\Support\Facades\DB;

/*
| Phase 8 §48–49: /health is minimal, stateless and reveals nothing about
| the installation.
*/

test('health reports app, database and cache status only', function () {
    $response = $this->get('/health');

    $response->assertOk()
        ->assertExactJson(['status' => 'ok', 'checks' => ['app' => 'ok', 'database' => 'ok', 'cache' => 'ok']])
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('health is stateless: no session or CSRF cookies and no login required', function () {
    $response = $this->get('/health');

    expect($response->headers->getCookies())->toBe([]);
});

test('health never exposes versions, paths, names or configuration', function () {
    $body = $this->get('/health')->getContent();

    foreach ([app()->version(), PHP_VERSION, config('crm.version'), base_path(), config('database.connections.'.config('database.default').'.database'), config('app.name'), 'mysql', 'sqlite', 'laravel'] as $needle) {
        if ($needle !== null && $needle !== '') {
            expect(strtolower($body))->not->toContain(strtolower((string) $needle));
        }
    }
});

test('a database outage returns 503 with a generic fail status', function () {
    DB::partialMock()->shouldReceive('select')->andThrow(new RuntimeException('SQLSTATE[HY000] [2002] db-host-01.internal refused'));

    $response = $this->get('/health');

    $response->assertStatus(503)->assertJsonPath('status', 'fail')->assertJsonPath('checks.database', 'fail');
    expect($response->getContent())->not->toContain('db-host-01')->not->toContain('SQLSTATE');
});

test('the default Laravel /up endpoint is not exposed', function () {
    $this->get('/up')->assertNotFound();
});

test('health is rate limited', function () {
    foreach (range(1, 60) as $i) {
        $this->get('/health')->assertOk();
    }
    $this->get('/health')->assertStatus(429);
});
