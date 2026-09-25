<?php

use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->org = salesOrg();
    $this->integration = telephonySetup([$this->org->rahul, $this->org->priya]);
});

test('M: no call export, bulk recording download or ZIP route exists', function () {
    $uris = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'call') || str_contains($route->uri(), 'telephony') || str_contains($route->uri(), 'recording'))
        ->map(fn ($route) => $route->uri())
        ->values();

    expect($uris->filter(fn ($uri) => preg_match('/export|csv|xlsx|zip|bulk|archive/i', $uri)))->toBeEmpty()
        ->and($uris->filter(fn ($uri) => str_contains($uri, 'recording/download')))->toHaveCount(1)
        ->and($uris->first(fn ($uri) => str_contains($uri, 'recording/download')))->toContain('{call}');
});

test('H: browser session credentials are never logged', function () {
    Log::spy();

    $this->actingAs($this->org->rahul)->postJson(route('telephony.session'))->assertOk();

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');
});

test('H: the softphone keeps credentials in memory only', function () {
    $source = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents(resource_path('js/Composables/useTelephony.js')));

    expect($source)->not->toContain('localStorage')
        ->not->toContain('sessionStorage')
        ->not->toContain('console.log')
        ->not->toContain('document.cookie');
});

test('H: telephony credentials are not shared with Inertia pages', function () {
    config([
        'telephony.exotel.api_key' => 'k-secret-value',
        'telephony.exotel.api_token' => 't-secret-value',
        'telephony.exotel.webrtc_access_token' => 'w-secret-value',
    ]);
    $lead = Lead::factory()->assignedTo($this->org->rahul)->create(['phone' => '9876543210', 'normalized_phone' => '919876543210']);

    foreach ([route('dashboard'), route('leads.show', $lead), route('calls.index')] as $url) {
        $content = $this->actingAs($this->org->rahul)->get($url)->assertOk()->getContent();
        expect($content)->not->toContain('k-secret-value')->not->toContain('t-secret-value')->not->toContain('w-secret-value')->not->toContain(TELEPHONY_TEST_TOKEN);
    }
});

test('the calls list uses a bounded number of queries', function () {
    $leads = Lead::factory()->count(3)->assignedTo($this->org->rahul)->create();
    foreach ($leads as $lead) {
        finishCall($this, startCall($lead, $this->org->rahul));
    }

    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->org->manager)->get(route('calls.index'))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $few = $count();
    $more = Lead::factory()->count(10)->assignedTo($this->org->priya)->create();
    foreach ($more as $lead) {
        finishCall($this, startCall($lead, $this->org->priya));
    }

    expect($count())->toBeLessThanOrEqual($few + 2);
});
