<?php

/*
| Phase 8 §18–20: security headers, CSP compatible with Meta / Exotel /
| audio / Vue assets, no wildcard sources, and no CORS for the session app.
*/

beforeEach(function () {
    $this->org = salesOrg();
});

function cspOf($response): array
{
    $header = (string) $response->headers->get('Content-Security-Policy');

    return collect(explode(';', $header))->map(fn ($d) => preg_split('/\s+/', trim($d)))
        ->filter(fn ($parts) => $parts[0] !== '')
        ->mapWithKeys(fn ($parts) => [$parts[0] => array_slice($parts, 1)])
        ->all();
}

test('HTML pages carry a nonce-based CSP that matches the inline scripts', function () {
    $response = $this->actingAs($this->org->rahul)->get('/dashboard')->assertOk();
    $csp = cspOf($response);

    $nonce = collect($csp['script-src'])->first(fn ($s) => str_starts_with($s, "'nonce-"));
    expect($nonce)->not->toBeNull();
    $value = substr($nonce, 7, -1);

    preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/', $response->getContent(), $inline);
    expect($inline[1])->not->toBeEmpty();
    foreach ($inline[1] as $attributes) {
        expect($attributes)->toContain("nonce=\"{$value}\"");
    }

    expect($csp['script-src'])->not->toContain("'unsafe-inline'")->not->toContain("'unsafe-eval'")
        ->and($csp['object-src'])->toBe(["'none'"])
        ->and($csp['frame-ancestors'])->toBe(["'self'"])
        ->and($csp['base-uri'])->toBe(["'self'"]);
});

test('no directive allows every origin', function () {
    $csp = cspOf($this->get('/login'));

    foreach ($csp as $directive => $sources) {
        expect($sources)->not->toContain('*', "{$directive} must not use *")
            ->not->toContain('https:', "{$directive} must not allow any https origin")
            ->not->toContain('http:');
    }
});

test('the CSP allows the integrations the CRM needs', function () {
    config(['telephony.exotel.webrtc_sdk_url' => 'https://sdk.exotel.example/crm-web-sdk.js']);
    $csp = cspOf($this->get('/login'));

    expect($csp['script-src'])->toContain('https://sdk.exotel.example')
        ->and($csp['connect-src'])->toContain('wss://*.exotel.com')
        ->and($csp['media-src'])->toContain("'self'")->toContain('blob:')
        ->and($csp['form-action'])->toContain('https://www.facebook.com')
        ->and($csp['font-src'])->toContain('https://fonts.bunny.net')
        ->and($csp['worker-src'])->toContain("'self'");
});

test('an insecure SDK URL is never added to script-src', function () {
    config(['telephony.exotel.webrtc_sdk_url' => 'http://sdk.exotel.example/sdk.js']);

    expect(cspOf($this->get('/login'))['script-src'])->not->toContain('http://sdk.exotel.example');
});

test('report-only mode sends the report-only header instead', function () {
    config(['security.csp.report_only' => true]);
    $response = $this->get('/login');

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'");
});

test('baseline headers are present and allow the microphone for browser calling only on this origin', function () {
    $response = $this->get('/login');

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    expect($response->headers->get('Permissions-Policy'))->toContain('microphone=(self)')->toContain('camera=()');
});

test('HSTS is sent on HTTPS requests', function () {
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('JSON responses do not carry an HTML CSP', function () {
    $response = $this->actingAs($this->org->rahul)
        ->getJson(route('calendar.events', ['start' => '2026-09-01T00:00:00', 'end' => '2026-09-08T00:00:00']))
        ->assertOk();

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('cross-origin requests get no CORS grant', function () {
    $this->actingAs($this->org->rahul)
        ->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'GET'])
        ->options('/leads')
        ->assertHeaderMissing('Access-Control-Allow-Origin');

    $this->actingAs($this->org->rahul)->withHeaders(['Origin' => 'https://evil.example'])->get('/dashboard')
        ->assertHeaderMissing('Access-Control-Allow-Origin')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');

    expect(config('cors.paths'))->toBe([])->and(config('cors.allowed_origins'))->not->toContain('*');
});

test('session cookies default to secure in production', function () {
    $config = require config_path('session.php');
    expect(array_key_exists('secure', $config))->toBeTrue()
        ->and(file_get_contents(config_path('session.php')))->toContain("env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production')")
        ->and(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');
});
