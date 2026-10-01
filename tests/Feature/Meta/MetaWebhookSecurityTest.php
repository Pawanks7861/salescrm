<?php

use App\Models\AuditLog;
use App\Models\FacebookWebhookEvent;
use App\Models\Lead;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->meta = metaSetup();
});

// --- MetaWebhookVerificationTest -------------------------------------------------

test('GET verification echoes the raw challenge for a valid token', function () {
    $response = $this->get('/webhooks/meta/leads?hub.mode=subscribe&hub.verify_token='.META_TEST_VERIFY.'&hub.challenge=1158201444');

    $response->assertOk();
    expect($response->getContent())->toBe('1158201444');
    expect($response->headers->get('Content-Type'))->toContain('text/plain');
});

test('GET verification rejects a wrong token, wrong mode or missing token with 403', function (string $query) {
    $this->get('/webhooks/meta/leads?'.$query)->assertForbidden();
})->with([
    'wrong token' => 'hub.mode=subscribe&hub.verify_token=nope&hub.challenge=123',
    'wrong mode' => 'hub.mode=unsubscribe&hub.verify_token='.META_TEST_VERIFY.'&hub.challenge=123',
    'no token' => 'hub.mode=subscribe&hub.challenge=123',
    'no params' => '',
]);

test('GET verification fails closed when no verify token is configured', function () {
    config(['meta.webhook_verify_token' => null]);

    $this->get('/webhooks/meta/leads?hub.mode=subscribe&hub.verify_token=&hub.challenge=123')->assertForbidden();
});

test('verification failure never logs or audits the verify token', function () {
    $this->get('/webhooks/meta/leads?hub.mode=subscribe&hub.verify_token=attacker-guess-123&hub.challenge=1');

    $audit = AuditLog::where('action', 'FACEBOOK_WEBHOOK_REJECTED')->first();
    expect($audit)->not->toBeNull();
    expect(json_encode($audit->toArray()))->not->toContain('attacker-guess-123')->not->toContain(META_TEST_VERIFY);
});

// --- MetaWebhookSignatureTest ----------------------------------------------------

test('valid signature is accepted and returns 200 quickly', function () {
    metaFakeGraph(['9001' => metaLead('9001')]);

    metaPost($this, metaPayload(metaChange('9001')))->assertOk()->assertSee('EVENT_RECEIVED');

    expect(FacebookWebhookEvent::where('leadgen_id', '9001')->exists())->toBeTrue();
});

test('missing signature is rejected with 403 and creates nothing', function () {
    metaPost($this, metaPayload(metaChange('9002')), sign: false)->assertForbidden();

    expect(FacebookWebhookEvent::count())->toBe(0)->and(Lead::count())->toBe(0);
    Http::assertNothingSent();
});

test('invalid signature is rejected with 403 and creates nothing', function (string $signature) {
    metaPost($this, metaPayload(metaChange('9003')), $signature)->assertForbidden();

    expect(FacebookWebhookEvent::count())->toBe(0)->and(Lead::count())->toBe(0);
})->with([
    'wrong secret' => fn () => metaSign(json_encode(metaPayload(metaChange('9003'))), 'another-secret'),
    'sha1 prefix' => fn () => 'sha1='.hash_hmac('sha1', json_encode(metaPayload(metaChange('9003'))), META_TEST_SECRET),
    'garbage' => 'sha256=not-a-hex-digest',
    'empty' => '',
    'bare hex' => fn () => hash_hmac('sha256', json_encode(metaPayload(metaChange('9003'))), META_TEST_SECRET),
]);

test('signature over a different body is rejected (tampered payload)', function () {
    $original = json_encode(metaPayload(metaChange('9004')));
    $tampered = json_encode(metaPayload(metaChange('9005')));

    metaPost($this, $tampered, metaSign($original))->assertForbidden();
    expect(FacebookWebhookEvent::count())->toBe(0);
});

test('RELEASE A: signature is computed over the ORIGINAL raw bytes, not re-encoded JSON', function () {
    metaFakeGraph(['9006' => metaLead('9006')]);

    // Meta-style body: extra whitespace, escaped unicode and slashes. Decoding and
    // re-encoding would change the bytes (and therefore the HMAC).
    $raw = '{"object": "page",  "entry": [ {"id": "111", "time": 1790000000, "changes": [ {"field": "leadgen", "value": {"leadgen_id": "9006", "page_id": "111", "form_id": "222", "note": "caf\\u00e9 \\/ test"}} ]} ]}';
    $reEncoded = json_encode(json_decode($raw, true));
    expect($reEncoded)->not->toBe($raw);

    // A signature of the re-encoded JSON must NOT validate the raw body…
    metaPost($this, $raw, metaSign($reEncoded))->assertForbidden();
    expect(FacebookWebhookEvent::count())->toBe(0);

    // …while the signature of the exact raw bytes does.
    metaPost($this, $raw, metaSign($raw))->assertOk();
    expect(FacebookWebhookEvent::where('leadgen_id', '9006')->exists())->toBeTrue();
});

test('uppercase hex digest is accepted (constant-time compare of normalised digest)', function () {
    metaFakeGraph(['9007' => metaLead('9007')]);
    $raw = json_encode(metaPayload(metaChange('9007')));

    metaPost($this, $raw, 'sha256='.strtoupper(hash_hmac('sha256', $raw, META_TEST_SECRET)))->assertOk();
});

test('webhook fails closed when the app secret is not configured', function () {
    config(['meta.app_secret' => null]);
    $raw = json_encode(metaPayload(metaChange('9008')));

    metaPost($this, $raw, 'sha256='.hash_hmac('sha256', $raw, ''))->assertForbidden();
    expect(FacebookWebhookEvent::count())->toBe(0);
});

test('webhook needs no session or CSRF token but CSRF stays enabled for the app', function () {
    metaFakeGraph(['9009' => metaLead('9009')]);

    metaPost($this, metaPayload(metaChange('9009')))->assertOk()->assertCookieMissing(config('session.cookie'));

    $routes = app('router')->getRoutes();
    $webhook = $routes->getByName('webhooks.meta.receive');
    expect($webhook->gatherMiddleware())->not->toContain('web');
    expect($routes->getByName('leads.store')->gatherMiddleware())->toContain('web');
    expect($routes->getByName('admin.integrations.facebook.connect')->gatherMiddleware())->toContain('web');
    expect(ValidateCsrfToken::class)->not->toBeNull();
    expect((new ReflectionProperty(ValidateCsrfToken::class, 'neverVerify'))->getValue())->toBe([]);
});

test('malformed JSON with a valid signature returns 400 and creates nothing', function () {
    $raw = '{"object":"page","entry":[';

    metaPost($this, $raw, metaSign($raw))->assertStatus(400);
    expect(FacebookWebhookEvent::count())->toBe(0);
});

test('oversized payload is rejected with 413 before any processing', function () {
    config(['meta.max_payload_bytes' => 1024]);
    $raw = json_encode(metaPayload(metaChange('9010')) + ['pad' => str_repeat('x', 2048)]);

    metaPost($this, $raw, metaSign($raw))->assertStatus(413);
    expect(FacebookWebhookEvent::count())->toBe(0);
});

test('repeated invalid signatures are throttled per IP', function () {
    config(['meta.invalid_signature_limit' => 3]);

    foreach (range(1, 3) as $i) {
        metaPost($this, metaPayload(metaChange("91{$i}")), 'sha256='.str_repeat('0', 64))->assertForbidden();
    }
    metaPost($this, metaPayload(metaChange('9199')), 'sha256='.str_repeat('0', 64))->assertStatus(429);
});

test('rejections are audited at most once per minute per IP and reason', function () {
    foreach (range(1, 4) as $i) {
        metaPost($this, metaPayload(metaChange("92{$i}")), 'sha256='.str_repeat('0', 64));
    }

    expect(AuditLog::where('action', 'FACEBOOK_WEBHOOK_REJECTED')->count())->toBe(1);
});

test('webhook errors never render HTML or debug pages', function () {
    $response = metaPost($this, metaPayload(metaChange('9300')), 'sha256=bad');

    $response->assertForbidden();
    expect($response->getContent())->toBe('Forbidden')->not->toContain('<html');
});

test('there is no HTTP route that bypasses signature validation', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'meta') || str_contains($r->uri(), 'webhook') || str_contains($r->uri(), 'facebook'))
        ->reject(fn ($r) => str_starts_with($r->uri(), 'admin/integrations/facebook'))
        ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())
        ->values()->all();

    expect($routes)->toEqualCanonicalizing(['GET|HEAD webhooks/meta/leads', 'POST webhooks/meta/leads']);
});

test('meta:test-lead refuses to run outside local and testing environments', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('meta:test-lead')->assertFailed();
    expect(FacebookWebhookEvent::count())->toBe(0);
});
