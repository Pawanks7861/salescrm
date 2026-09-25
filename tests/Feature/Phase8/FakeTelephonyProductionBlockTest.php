<?php

use App\Enums\CallStatus;
use App\Models\Lead;
use App\Services\Telephony\TelephonyManager;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Artisan;

/*
| Phase 8 §6: the local telephony simulator is unreachable in production,
| server-side (not just hidden in the UI).
*/

beforeEach(function () {
    $this->org = salesOrg();
    telephonySetup([$this->org->rahul]);
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

test('the fake provider cannot be resolved in production or staging', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(TelephonyManager::fakeAllowed())->toBeFalse()
        ->and(fn () => (new TelephonyManager)->provider())->toThrow(RuntimeException::class);
})->with(['production', 'staging']);

test('simulator endpoints return 404 in production and leave calls untouched', function () {
    $call = startCall($this->lead, $this->org->rahul);
    app()->detectEnvironment(fn () => 'production');
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->actingAs($this->org->rahul)->postJson(route('telephony.fake.simulate', $call), ['status' => 'completed'])->assertNotFound();
    $this->actingAs($this->org->rahul)->postJson(route('telephony.fake.incoming'), ['number' => '+919876543210'])->assertNotFound();

    expect($call->fresh()->status)->not->toBe(CallStatus::Completed);
});

test('simulator endpoints return 404 when the real driver is configured, even locally', function () {
    $call = startCall($this->lead, $this->org->rahul);
    config(['telephony.driver' => 'exotel']);
    app()->forgetInstance(TelephonyManager::class);
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->actingAs($this->org->rahul)->postJson(route('telephony.fake.simulate', $call), ['status' => 'completed'])->assertNotFound();
});

test('the production check fails while TELEPHONY_DRIVER=fake', function () {
    config(['telephony.driver' => 'fake']);

    expect(Artisan::call('app:production-check'))->toBe(1)
        ->and(Artisan::output())->toContain('TELEPHONY_DRIVER=fake is for local development only');
});
