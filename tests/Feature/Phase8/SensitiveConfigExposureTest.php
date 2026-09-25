<?php

use Illuminate\Support\Facades\Artisan;

/*
| Phase 8 §3 / §87: credentials configured in the environment never reach
| the browser (page HTML or Inertia props), health output or check output.
*/

beforeEach(function () {
    $this->secrets = [
        'app.key' => 'base64:'.base64_encode(str_repeat('Q', 32)),
        'meta.app_secret' => 'META-SECRET-EXPOSE-1',
        'meta.webhook_verify_token' => 'META-VERIFY-EXPOSE-2',
        'telephony.exotel.api_key' => 'EXOTEL-KEY-EXPOSE-3',
        'telephony.exotel.api_token' => 'EXOTEL-TOKEN-EXPOSE-4',
        'telephony.exotel.webhook_secret' => 'EXOTEL-HOOK-EXPOSE-5',
        'telephony.exotel.webrtc_access_token' => 'EXOTEL-WEBRTC-EXPOSE-6',
        'webpush.vapid.private_key' => 'VAPID-PRIVATE-EXPOSE-7',
        'database.connections.mysql.password' => 'DB-PASSWORD-EXPOSE-8',
        'mail.mailers.smtp.password' => 'SMTP-PASSWORD-EXPOSE-9',
    ];
    config($this->secrets);
    $this->org = salesOrg();
});

function assertNoSecrets(string $haystack, array $secrets): void
{
    foreach ($secrets as $key => $value) {
        expect($haystack)->not->toContain($value, "{$key} leaked");
    }
}

test('admin pages never contain configured secrets', function (string $url) {
    $html = $this->actingAs($this->org->super)->get($url)->assertOk()->getContent();

    assertNoSecrets($html, $this->secrets);
})->with([
    '/dashboard',
    '/admin/settings',
    '/admin/integrations/telephony',
    '/admin/integrations/facebook',
    '/profile',
]);

test('Inertia shared props expose only branding, platform and user data', function () {
    $props = $this->actingAs($this->org->rahul)->get('/dashboard')->viewData('page')['props'];

    expect(array_keys($props['app']))->toEqualCanonicalizing(['name', 'timezone', 'company', 'logo_url', 'favicon_url'])
        ->and($props['platform'])->toBe(['name' => 'Buildify360', 'url' => 'https://buildify360.com']);
    assertNoSecrets(json_encode($props), $this->secrets);
});

test('the browser telephony session never returns provider API credentials', function () {
    telephonySetup([$this->org->rahul]);

    $json = $this->actingAs($this->org->rahul)->postJson(route('telephony.session'))->getContent();

    foreach (['EXOTEL-KEY-EXPOSE-3', 'EXOTEL-TOKEN-EXPOSE-4', 'EXOTEL-HOOK-EXPOSE-5'] as $secret) {
        expect($json)->not->toContain($secret);
    }
});

test('health and production-check output contain no secrets', function () {
    assertNoSecrets($this->get('/health')->getContent(), $this->secrets);

    Artisan::call('app:production-check');
    assertNoSecrets(Artisan::output(), $this->secrets);
});

test('.env.example contains placeholders only', function () {
    $lines = preg_split('/\R/', file_get_contents(base_path('.env.example')));

    foreach ($lines as $line) {
        if (preg_match('/^([A-Z0-9_]*(SECRET|TOKEN|PASSWORD|API_KEY|PRIVATE_KEY|ACCOUNT_SID|APP_KEY|ACCESS_KEY)[A-Z0-9_]*)=(.*)$/', $line, $m) && ! str_ends_with($m[1], '_TOKEN_FORM')) {
            expect(in_array(trim($m[3]), ['', 'null', 'false'], true))->toBeTrue("{$m[1]} must be empty in .env.example");
        }
    }
});

test('.env and runtime data are git-ignored', function () {
    $gitignore = file_get_contents(base_path('.gitignore'));

    expect($gitignore)->toContain(".env\n")->toContain(".env.*\n")->toContain("!.env.example\n")
        ->toContain('/vendor')->toContain('/node_modules')->toContain('*.sql')->toContain('/storage/app/private/*');
});
