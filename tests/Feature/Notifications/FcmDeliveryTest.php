<?php

use App\Jobs\SendFcmNotification;
use App\Models\FcmToken;
use App\Notifications\Channels\WebPushChannel;
use App\Services\Notifications\FcmService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->org = salesOrg();
    $this->rahul = pushOptIn($this->org->rahul);
    $this->token = 'fcmDeviceToken:'.str_repeat('a', 80);
});

function fcmConfigure(bool $web = true): void
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    expect($key)->not->toBeFalse();
    openssl_pkey_export($key, $pem);

    config([
        'fcm.project_id' => 'crm-test',
        'fcm.client_email' => 'fcm-test@crm-test.iam.gserviceaccount.com',
        'fcm.private_key' => $pem,
        'fcm.web.api_key' => $web ? 'web-api-key-public' : null,
        'fcm.web.auth_domain' => 'crm-test.firebaseapp.com',
        'fcm.web.project_id' => 'crm-test',
        'fcm.web.messaging_sender_id' => '1234567890',
        'fcm.web.app_id' => '1:1234567890:web:abc',
        'fcm.web.vapid_key' => $web ? 'BPublicWebPushCertificate' : null,
    ]);
}

function fcmFake(int $status = 200, array $body = ['name' => 'projects/crm-test/messages/1']): void
{
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test-access', 'expires_in' => 3600]),
        'https://fcm.googleapis.com/*' => Http::response($body, $status),
    ]);
}

test('a user can register a device token and the response does not echo it', function () {
    fcmConfigure();

    $this->actingAs($this->rahul)
        ->postJson('/fcm-tokens', ['token' => $this->token])
        ->assertCreated()
        ->assertJson(['registered' => true])
        ->assertDontSee($this->token);

    $row = FcmToken::query()->where('user_id', $this->rahul->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->token)->toBe($this->token)
        ->and($row->token_hash)->toBe(hash('sha256', $this->token));
});

test('registration is refused when FCM is not configured and guests cannot register', function () {
    $this->actingAs($this->rahul)->postJson('/fcm-tokens', ['token' => $this->token])->assertStatus(409);
    $this->postJson('/fcm-tokens', ['token' => $this->token])->assertUnauthorized();
});

test('a user cannot remove another user\'s token', function () {
    fcmConfigure();
    app(FcmService::class)->register($this->rahul, $this->token, 'Pest');

    $this->actingAs($this->org->priya)
        ->deleteJson('/fcm-tokens', ['token' => $this->token])
        ->assertOk()
        ->assertJson(['removed' => false]);

    expect(FcmToken::query()->where('user_id', $this->rahul->id)->count())->toBe(1);
});

test('a data message is posted to FCM and an unregistered token is deleted', function () {
    fcmConfigure();
    fcmFake();
    $row = app(FcmService::class)->register($this->rahul, $this->token, 'Pest');
    $payload = WebPushChannel::payload('note-1', 'NEW_LEAD_ASSIGNED', 'New lead', 'Assigned to you', '/leads/1');

    $logged = '';
    Log::listen(function ($event) use (&$logged) {
        $logged .= $event->message.' '.json_encode($event->context);
    });

    $result = app(FcmService::class)->deliver($this->rahul, $payload);

    expect($result->delivered)->toBe([$row->id])
        ->and($logged)->not->toContain($this->token)
        ->and($logged)->not->toContain('BEGIN PRIVATE KEY');

    $token = $this->token;
    Http::assertSent(function ($request) use ($token) {
        return $request->url() === 'https://fcm.googleapis.com/v1/projects/crm-test/messages:send'
            && $request->hasHeader('Authorization', 'Bearer ya29.test-access')
            && $request['message']['token'] === $token
            && $request['message']['data']['id'] === 'note-1'
            && $request['message']['data']['title'] === 'New lead'
            && ! isset($request['message']['notification']);
    });

    fcmFake(404, ['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]]);
    app(FcmService::class)->deliver($this->rahul->fresh(), $payload);

    expect(FcmToken::query()->whereKey($row->id)->exists())->toBeFalse();
});

test('the channel stays quiet unless FCM is configured', function () {
    Queue::fake();
    app(FcmService::class)->register($this->rahul, $this->token, 'Pest');
    $lead = \App\Models\Lead::factory()->create(['assigned_to' => $this->rahul->id, 'created_by' => $this->org->super->id]);

    $this->rahul->notify(new \App\Notifications\Leads\LeadAssignedNotification($lead));

    Queue::assertNotPushed(SendFcmNotification::class);
});

test('the channel queues a send when FCM is configured and the user has a token', function () {
    fcmConfigure(web: false);
    Queue::fake();
    app(FcmService::class)->register($this->rahul, $this->token, 'Pest');
    $lead = \App\Models\Lead::factory()->create(['assigned_to' => $this->rahul->id, 'created_by' => $this->org->super->id]);

    $this->rahul->notify(new \App\Notifications\Leads\LeadAssignedNotification($lead));

    Queue::assertPushed(SendFcmNotification::class, fn ($job) => $job->userId === $this->rahul->id && $job->payload['event'] !== '');
});

test('the service worker embeds only the public web config', function () {
    fcmConfigure();

    $body = $this->get('/firebase-messaging-sw.js')->assertOk()->headers->get('Content-Type');
    expect($body)->toContain('javascript');

    $script = $this->get('/firebase-messaging-sw.js')->getContent();
    expect($script)->toContain('web-api-key-public')
        ->and($script)->toContain('onBackgroundMessage')
        ->and($script)->not->toContain('BEGIN PRIVATE KEY')
        ->and($script)->not->toContain('PRIVATE KEY');

    config(['fcm.private_key' => null]);
    $this->get('/firebase-messaging-sw.js')->assertNotFound();
});

test('shared props expose the public web config and never the private key', function () {
    fcmConfigure();
    pushConfigure();

    $props = $this->actingAs($this->rahul)->get('/dashboard')->viewData('page')['props'];

    expect($props['push']['fcm']['projectId'])->toBe('crm-test')
        ->and($props['push']['fcm']['apiKey'])->toBe('web-api-key-public')
        ->and(json_encode($props))->not->toContain('BEGIN PRIVATE KEY');
});
