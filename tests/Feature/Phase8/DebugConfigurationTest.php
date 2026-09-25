<?php

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Phase 8 §15 / §50: with APP_DEBUG=false no exception detail reaches the
| browser, and every error status has a professional page.
*/

beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('web')->group(function () {
        Route::get('/_phase8/boom', fn () => throw new RuntimeException('Internal detail: /var/www/secret-path SQLSTATE password=hunter2'));
        Route::get('/_phase8/abort/{status}', fn (int $status) => abort($status));
    });
});

test('an unhandled exception renders the generic error page without internals', function () {
    $response = $this->get('/_phase8/boom');

    $response->assertStatus(500)
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 500));
    expect($response->getContent())
        ->not->toContain('Internal detail')
        ->not->toContain('secret-path')
        ->not->toContain('hunter2')
        ->not->toContain('RuntimeException')
        ->not->toContain('vendor/laravel');
});

test('JSON clients get a generic message without the exception text', function () {
    $response = $this->getJson('/_phase8/boom');

    $response->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
});

test('error statuses render the CRM error page', function (int $status) {
    $this->get("/_phase8/abort/{$status}")
        ->assertStatus($status)
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', $status));
})->with([403, 404, 429, 500]);

test('503 uses the static maintenance page that needs no database or build assets', function () {
    $response = $this->get('/_phase8/abort/503');

    $response->assertStatus(503)->assertSee('Down for maintenance');
    expect($response->getContent())->not->toContain('/build/')->not->toContain('data-page');
});

test('static error views exist for every status and are self-contained', function (int $status) {
    $html = view("errors.{$status}")->render();

    expect($html)->toContain((string) $status)
        ->toContain('<style>')
        ->not->toContain('<script')
        ->not->toContain('/build/')
        ->not->toContain('fonts.bunny.net');
})->with([403, 404, 419, 429, 500, 503]);

test('the error page does not claim that anyone was notified', function () {
    expect(view('errors.500')->render())->not->toContain('notified');
    expect(file_get_contents(resource_path('js/Pages/Error.vue')))->not->toContain('notified')
        ->not->toContain('@click="history.back()"');
});

test('debug stays off in the committed environment template', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toContain("APP_DEBUG=false\n")
        ->toContain("APP_ENV=production\n")
        ->toContain("SESSION_SECURE_COOKIE=true\n")
        ->not->toMatch('/^LOG_LEVEL=debug$/m');
});
