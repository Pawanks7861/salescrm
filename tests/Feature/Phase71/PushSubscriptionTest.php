<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\PushSubscription;
use App\Services\Notifications\PushTransport;
use App\Services\Notifications\WebPushService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    pushConfigure();
    $this->org = salesOrg();
});

test('a user subscribes their own browser; keys are stored encrypted and never echoed', function () {
    $this->actingAs($this->org->rahul)
        ->postJson('/push-subscriptions', pushBody('rahul-laptop'))
        ->assertCreated()
        ->assertExactJson(['subscribed' => true]);

    $sub = PushSubscription::sole();
    expect($sub->user_id)->toBe($this->org->rahul->id)
        ->and($sub->endpoint)->toBe(pushEndpoint('rahul-laptop'))
        ->and($sub->toArray())->not->toHaveKeys(['endpoint', 'public_key', 'auth_token']);

    $raw = (array) DB::table('push_subscriptions')->first();
    expect($raw['endpoint'])->not->toContain('fcm.googleapis.com')
        ->and($raw['auth_token'])->not->toBe(pushBody('x')['keys']['auth']);
});

test('a user can register several browsers', function () {
    $this->actingAs($this->org->rahul)->postJson('/push-subscriptions', pushBody('laptop'))->assertCreated();
    $this->actingAs($this->org->rahul)->postJson('/push-subscriptions', pushBody('phone'))->assertCreated();
    $this->actingAs($this->org->rahul)->postJson('/push-subscriptions', pushBody('phone'))->assertCreated();

    expect($this->org->rahul->pushSubscriptions()->count())->toBe(2);
});

test('the owner is always the signed-in user, never request input', function () {
    $this->actingAs($this->org->rahul)
        ->postJson('/push-subscriptions', [...pushBody('x'), 'user_id' => $this->org->priya->id])
        ->assertCreated();

    expect(PushSubscription::sole()->user_id)->toBe($this->org->rahul->id)
        ->and($this->org->priya->pushSubscriptions()->count())->toBe(0);
});

test('a user cannot remove another user\'s subscription', function () {
    pushSubscribe($this->org->priya, 'priya-phone');

    $this->actingAs($this->org->rahul)
        ->deleteJson('/push-subscriptions', ['endpoint' => pushEndpoint('priya-phone')])
        ->assertOk()->assertJson(['removed' => false]);

    expect($this->org->priya->pushSubscriptions()->count())->toBe(1);
});

test('unsubscribe removes only the current browser', function () {
    pushSubscribe($this->org->rahul, 'laptop');
    pushSubscribe($this->org->rahul, 'phone');

    $this->actingAs($this->org->rahul)
        ->deleteJson('/push-subscriptions', ['endpoint' => pushEndpoint('laptop')])
        ->assertOk()->assertJson(['removed' => true]);

    expect($this->org->rahul->pushSubscriptions()->sole()->endpoint)->toBe(pushEndpoint('phone'));
});

test('endpoints must be HTTPS on a known push service', function (string $endpoint) {
    $this->actingAs($this->org->rahul)
        ->postJson('/push-subscriptions', [...pushBody('x'), 'endpoint' => $endpoint])
        ->assertUnprocessable()->assertJsonValidationErrors('endpoint');

    expect(PushSubscription::count())->toBe(0);
})->with([
    'http' => 'http://fcm.googleapis.com/fcm/send/abc',
    'internal host' => 'https://127.0.0.1/push',
    'lookalike' => 'https://fcm.googleapis.com.evil.test/x',
    'not a url' => 'javascript:alert(1)',
]);

test('subscribing is refused when the admin switched browser notifications off or VAPID is missing', function () {
    app(SettingService::class)->put('notifications.browser_enabled', false);
    $this->actingAs($this->org->rahul)->postJson('/push-subscriptions', pushBody('x'))->assertStatus(409);

    app(SettingService::class)->put('notifications.browser_enabled', true);
    config(['webpush.vapid.private_key' => null]);
    $this->actingAs($this->org->rahul)->postJson('/push-subscriptions', pushBody('x'))->assertStatus(409);

    expect(PushSubscription::count())->toBe(0);
});

test('guests cannot subscribe', function () {
    $this->postJson('/push-subscriptions', pushBody('x'))->assertUnauthorized();
});

test('expired subscriptions are deleted after a send and never retried', function () {
    $rahul = pushOptIn($this->org->rahul);
    $gone = pushSubscribe($rahul, 'old-browser');
    $ok = pushSubscribe($rahul, 'laptop');
    $transport = pushFakeTransport([$gone->id => ['ok' => false, 'expired' => true, 'status' => 410]]);

    $delivered = app(WebPushService::class)->send($rahul, ['id' => 'n-1', 'event' => 'NEW_LEAD_ASSIGNED', 'title' => 'T', 'body' => 'B', 'url' => '/x']);

    expect($delivered)->toBe(1)
        ->and(PushSubscription::whereKey($gone->id)->exists())->toBeFalse()
        ->and($ok->fresh()->last_used_at)->not->toBeNull()
        ->and($transport->sent)->toHaveCount(1);

    app(WebPushService::class)->send($rahul, ['id' => 'n-2', 'event' => 'NEW_LEAD_ASSIGNED', 'title' => 'T', 'body' => 'B', 'url' => '/x']);
    expect($transport->sent[1]['subscription_ids'])->toBe([$ok->id]);
});

test('a transport failure is swallowed and logs no endpoint or keys', function () {
    $rahul = pushOptIn($this->org->rahul);
    pushSubscribe($rahul, 'laptop');
    app()->instance(PushTransport::class, new class implements PushTransport
    {
        public function send(iterable $subscriptions, string $payload): array
        {
            throw new RuntimeException('network down '.pushEndpoint('laptop'));
        }
    });
    Log::spy();

    expect(app(WebPushService::class)->send($rahul, ['id' => 'n-1', 'event' => 'NEW_LEAD_ASSIGNED', 'title' => 'T', 'body' => 'B', 'url' => '/x']))->toBe(0);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
        return ! str_contains(json_encode($context), 'fcm.googleapis.com') && $context['error'] === 'RuntimeException';
    })->once();
});

test('shouldPush honours the global switch, the user preference and subscriptions', function () {
    $push = app(WebPushService::class);
    $rahul = $this->org->rahul;
    pushSubscribe($rahul, 'laptop');

    expect($push->shouldPush($rahul->fresh()))->toBeFalse(); // default OFF

    $rahul = pushOptIn($rahul);
    expect($push->shouldPush($rahul))->toBeTrue();

    app(SettingService::class)->put('notifications.browser_enabled', false);
    expect($push->shouldPush($rahul))->toBeFalse();
    app(SettingService::class)->put('notifications.browser_enabled', true);

    $rahul->pushSubscriptions()->delete();
    expect($push->shouldPush($rahul))->toBeFalse();
});

test('preference changes are saved and audited; each change once', function () {
    $this->actingAs($this->org->rahul)
        ->putJson('/profile/notifications', ['browser_notifications_enabled' => true, 'notification_sound_enabled' => false])
        ->assertOk()->assertExactJson(['browser_notifications_enabled' => true, 'notification_sound_enabled' => false]);
    $this->actingAs($this->org->rahul)->putJson('/profile/notifications', ['browser_notifications_enabled' => true])->assertOk();
    $this->actingAs($this->org->rahul)->putJson('/profile/notifications', ['browser_notifications_enabled' => false])->assertOk();

    $actions = AuditLog::where('user_id', $this->org->rahul->id)->whereIn('action', [
        AuditAction::BrowserNotificationsEnabled->value, AuditAction::BrowserNotificationsDisabled->value,
        AuditAction::NotificationSoundEnabled->value, AuditAction::NotificationSoundDisabled->value,
    ])->orderBy('id')->pluck('action')->all();

    expect($actions)->toBe(['BROWSER_NOTIFICATIONS_ENABLED', 'NOTIFICATION_SOUND_DISABLED', 'BROWSER_NOTIFICATIONS_DISABLED']);
});

test('logging out removes the push subscription of that browser only', function () {
    pushSubscribe($this->org->rahul, 'phone');
    $this->actingAs($this->org->rahul)->postJson('/push-subscriptions', pushBody('laptop'))->assertCreated();

    $this->post('/logout');

    expect($this->org->rahul->pushSubscriptions()->pluck('endpoint_hash')->all())
        ->toBe([PushSubscription::hashEndpoint(pushEndpoint('phone'))]);
});

test('the push prop exposes only the public key and availability', function () {
    $props = $this->actingAs($this->org->rahul)->get('/dashboard')->viewData('page')['props'];

    expect($props['push'])->toMatchArray(['available' => true, 'public_key' => config('webpush.vapid.public_key'), 'browser' => false, 'sound' => true])
        ->and(json_encode($props))->not->toContain(config('webpush.vapid.private_key'));
});
