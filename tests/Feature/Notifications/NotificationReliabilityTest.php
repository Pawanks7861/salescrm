<?php

use App\Console\Commands\ProductionCheck;
use App\Jobs\SendFollowupReminder;
use App\Jobs\SendWebPushNotification;
use App\Models\FollowupReminder;
use App\Models\Lead;
use App\Models\PushSubscription;
use App\Notifications\Followups\FollowupReminderNotification;
use App\Notifications\Leads\LeadAssignedNotification;
use App\Services\Notifications\PushEncryptionUnavailable;
use App\Services\Notifications\PushTransport;
use App\Services\Notifications\WebPushService;
use App\Services\SettingService;
use App\Support\PushEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
| Release-blocking reliability checks for the two browser notifications
| (NEW_LEAD_ASSIGNED, FOLLOWUP_REMINDER): scheduler → claim → job → database
| notification → Web Push, exactly once, to the right user only.
*/
beforeEach(function () {
    pushConfigure();
    $this->org = salesOrg();
    $this->rahul = pushOptIn($this->org->rahul);
    $this->laptop = pushSubscribe($this->rahul, 'rahul-laptop');
    $this->phone = pushSubscribe($this->rahul, 'rahul-phone');
    $this->priya = pushOptIn($this->org->priya);
    pushSubscribe($this->priya, 'priya-laptop');

    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Kolkata'));
    $this->lead = Lead::factory()->assignedTo($this->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai', 'phone' => '9812345678']);
    $this->followup = scheduleFollowup($this->lead, $this->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '16:30', 'reminder_minutes' => 15]);
});

function dueNow(): void
{
    test()->travelTo(CarbonImmutable::parse('2026-09-23 16:16', 'Asia/Kolkata'));
}

function reminderPushJob(object $test, array $overrides = []): SendWebPushNotification
{
    return new SendWebPushNotification($test->rahul->id, [
        'id' => 'notif-1', 'event' => PushEvent::FOLLOWUP_REMINDER, 'title' => 'Follow-up Reminder',
        'body' => 'Your follow-up is due at 4:30 PM.', 'url' => '/notifications/notif-1/open', ...$overrides,
    ]);
}

describe('follow-up reminder pipeline', function () {
    test('running the scheduler three times gives exactly one notification and one push, to the assignee only', function () {
        $transport = pushFakeTransport();
        dueNow();

        Artisan::call('followups:dispatch-reminders');
        Artisan::call('followups:dispatch-reminders');
        Artisan::call('followups:dispatch-reminders');

        $notification = $this->rahul->notifications()->where('type', FollowupReminderNotification::class)->sole();
        expect($transport->sent)->toHaveCount(1)
            ->and($transport->sent[0]['subscription_ids'])->toEqualCanonicalizing([$this->laptop->id, $this->phone->id])
            ->and($transport->sent[0]['payload']['id'])->toBe($notification->id)
            ->and($this->priya->notifications()->count())->toBe(0)
            ->and($this->org->manager->notifications()->count())->toBe(0)
            ->and($this->followup->reminders()->where('kind', 'reminder')->sole()->status)->toBe(FollowupReminder::SENT);
    });

    test('the push payload is minimal and uses the FOLLOWUP_REMINDER event', function () {
        $transport = pushFakeTransport();
        dueNow();
        Artisan::call('followups:dispatch-reminders');

        $payload = $transport->sent[0]['payload'];
        expect(array_keys($payload))->toEqualCanonicalizing(['id', 'event', 'title', 'body', 'url', 'icon', 'sound', 'ts'])
            ->and($payload['event'])->toBe('FOLLOWUP_REMINDER')
            ->and($payload['title'])->toBe('Follow-up Reminder')
            ->and($payload['body'])->toBe('Call with Amit Desai at 4:30 PM.')
            ->and(json_encode($payload))->not->toContain('9812345678');
    });

    test('with names hidden the reminder body only gives the time', function () {
        $transport = pushFakeTransport();
        app(SettingService::class)->put('notifications.browser_show_names', false);
        dueNow();
        Artisan::call('followups:dispatch-reminders');

        expect($transport->sent[0]['payload']['body'])->toBe('Your follow-up is due at 4:30 PM.');
    });

    test('a push failure keeps the in-app notification and the reminder stays sent', function () {
        app()->instance(PushTransport::class, new class implements PushTransport
        {
            public function send(iterable $subscriptions, string $payload): array
            {
                throw new RuntimeException('push service down');
            }
        });
        dueNow();
        Artisan::call('followups:dispatch-reminders');

        expect($this->rahul->notifications()->where('type', FollowupReminderNotification::class)->count())->toBe(1)
            ->and($this->followup->reminders()->where('kind', 'reminder')->sole()->status)->toBe(FollowupReminder::SENT);
    });

    test('a reminder job queued twice (stale reclaim) delivers once: the second waits for the lock and finds it sent', function () {
        Queue::fake([SendWebPushNotification::class]);
        $row = $this->followup->reminders()->where('kind', 'reminder')->sole();
        $row->forceFill(['status' => FollowupReminder::PROCESSING, 'claimed_at' => now()])->save();

        $lock = Cache::lock("followup-reminder:{$row->id}", 120);
        $lock->get();
        app()->call([new SendFollowupReminder($row->id), 'handle']);
        expect($this->rahul->notifications()->count())->toBe(0);
        $lock->release();

        app()->call([new SendFollowupReminder($row->id), 'handle']);
        app()->call([new SendFollowupReminder($row->id), 'handle']);

        expect($this->rahul->notifications()->where('type', FollowupReminderNotification::class)->count())->toBe(1)
            ->and($row->fresh()->status)->toBe(FollowupReminder::SENT);
        Queue::assertPushed(SendWebPushNotification::class, 1);
    });

    test('lead reassigned before the click: the notification grants no access', function () {
        pushFakeTransport();
        dueNow();
        Artisan::call('followups:dispatch-reminders');
        $id = $this->rahul->notifications()->where('type', FollowupReminderNotification::class)->sole()->id;

        $this->actingAs($this->org->admin)->post("/leads/{$this->lead->id}/assign", ['assigned_to' => $this->org->outsider->id])->assertSessionHasNoErrors();

        $this->actingAs($this->rahul)->get("/notifications/{$id}/open")->assertRedirect(route('notifications.index'));
        $this->actingAs($this->rahul)->get("/follow-ups/{$this->followup->id}")->assertForbidden();
        $this->actingAs($this->rahul)->get("/leads/{$this->lead->id}")->assertForbidden();
    });

    test('a completed follow-up opens on its current state', function () {
        pushFakeTransport();
        dueNow();
        Artisan::call('followups:dispatch-reminders');
        $id = $this->rahul->notifications()->where('type', FollowupReminderNotification::class)->sole()->id;
        $this->followup->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $this->actingAs($this->rahul)->get("/notifications/{$id}/open")->assertRedirect("/follow-ups/{$this->followup->id}");
    });
});

describe('push retry and dead subscriptions', function () {
    test('a temporary push-service error is retried later, and only for browsers that did not get it', function () {
        $transport = pushFakeTransport([$this->phone->id => ['ok' => false, 'expired' => false, 'status' => 503]]);

        $job = reminderPushJob($this)->withFakeQueueInteractions();
        $job->handle(app(WebPushService::class));
        $job->assertReleased(SendWebPushNotification::RETRY_DELAYS[0]);

        $transport = pushFakeTransport();
        $retry = reminderPushJob($this)->withFakeQueueInteractions();
        $retry->job->attempts = 2;
        $retry->handle(app(WebPushService::class));

        expect($transport->sent)->toHaveCount(1)
            ->and($transport->sent[0]['subscription_ids'])->toBe([$this->phone->id]);
        $retry->assertNotReleased();
    });

    test('rate limiting and network errors are retried too', function () {
        pushFakeTransport([$this->laptop->id => ['ok' => false, 'expired' => false, 'status' => 429], $this->phone->id => ['ok' => false, 'expired' => false, 'status' => null]]);
        $job = reminderPushJob($this)->withFakeQueueInteractions();
        $job->handle(app(WebPushService::class));
        $job->assertReleased();
    });

    test('gives up after the last attempt and logs without secrets', function () {
        Log::spy();
        pushFakeTransport([$this->phone->id => ['ok' => false, 'expired' => false, 'status' => 500]]);
        $job = reminderPushJob($this)->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;
        $job->handle(app(WebPushService::class));

        $job->assertNotReleased();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'Browser push gave up after retries'
            && ! str_contains(json_encode($context), 'fcm.googleapis.com'));
    });

    test('an expired subscription (404/410) is deleted and never retried', function () {
        pushFakeTransport([$this->phone->id => ['ok' => false, 'expired' => true, 'status' => 410]]);
        $job = reminderPushJob($this)->withFakeQueueInteractions();
        $job->handle(app(WebPushService::class));

        $job->assertNotReleased();
        expect(PushSubscription::find($this->phone->id))->toBeNull()
            ->and(PushSubscription::find($this->laptop->id))->not->toBeNull();
    });

    test('a permanently rejected message is not retried and the subscription is kept', function () {
        pushFakeTransport([$this->phone->id => ['ok' => false, 'expired' => false, 'status' => 400]]);
        $job = reminderPushJob($this)->withFakeQueueInteractions();
        $job->handle(app(WebPushService::class));

        $job->assertNotReleased();
        expect(PushSubscription::find($this->phone->id))->not->toBeNull();
    });

    test('a server that cannot encrypt pushes logs a clear error instead of failing silently', function () {
        Log::spy();
        app()->instance(PushTransport::class, new class implements PushTransport
        {
            public function send(iterable $subscriptions, string $payload): array
            {
                throw new PushEncryptionUnavailable('no EC');
            }
        });
        $job = reminderPushJob($this)->withFakeQueueInteractions();
        $job->handle(app(WebPushService::class));

        $job->assertNotReleased();
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'OPENSSL_CONF'));
    });

    test('a re-run of the same push job never re-sends to browsers that already received it', function () {
        $transport = pushFakeTransport();
        reminderPushJob($this)->withFakeQueueInteractions()->handle(app(WebPushService::class));
        reminderPushJob($this)->withFakeQueueInteractions()->handle(app(WebPushService::class));

        expect($transport->sent)->toHaveCount(1);
    });
});

describe('new lead assigned', function () {
    test('admin assigns a lead to Rahul: one notification and one push for Rahul, none for Priya or the manager', function () {
        $transport = pushFakeTransport();
        $this->actingAs($this->org->admin)->post('/leads', leadPayload(['first_name' => 'Neha', 'last_name' => 'Shah', 'assigned_to' => $this->rahul->id]))->assertSessionHasNoErrors();

        $notification = $this->rahul->notifications()->where('type', LeadAssignedNotification::class)->sole();
        expect($transport->sent)->toHaveCount(1)
            ->and($transport->sent[0]['payload'])->toMatchArray([
                'id' => $notification->id,
                'event' => 'NEW_LEAD_ASSIGNED',
                'title' => 'New Lead Assigned',
                'body' => 'Neha Shah has been assigned to you.',
                'url' => "/notifications/{$notification->id}/open",
            ])
            ->and($this->priya->notifications()->count())->toBe(0)
            ->and($this->org->manager->notifications()->count())->toBe(0);
    });

    test('reassignment notifies only the new owner; the old owner gets nothing new', function () {
        $transport = pushFakeTransport();
        $this->actingAs($this->org->admin)->post("/leads/{$this->lead->id}/assign", ['assigned_to' => $this->priya->id])->assertSessionHasNoErrors();

        expect($this->priya->notifications()->where('type', LeadAssignedNotification::class)->count())->toBe(1)
            ->and($this->rahul->notifications()->where('type', LeadAssignedNotification::class)->count())->toBe(0)
            ->and($this->org->manager->notifications()->count())->toBe(0)
            ->and($transport->sent)->toHaveCount(1)
            ->and($transport->sent[0]['subscription_ids'])->toBe($this->priya->pushSubscriptions()->pluck('id')->all());
    });
});

describe('test notification endpoint', function () {
    test('queues a test push to the caller only, without storing a notification', function () {
        Queue::fake();
        $this->actingAs($this->rahul)->postJson('/profile/notifications/test', ['event' => 'NEW_LEAD_ASSIGNED', 'delay' => 10])
            ->assertStatus(202)->assertJson(['queued' => true, 'delay' => 10]);

        Queue::assertPushed(SendWebPushNotification::class, fn ($job) => $job->userId === $this->rahul->id
            && $job->payload['event'] === 'NEW_LEAD_ASSIGNED'
            && $job->payload['title'] === 'New Lead Assigned'
            && str_starts_with($job->payload['id'], 'test-'));
        expect($this->rahul->notifications()->count())->toBe(0);
    });

    test('refuses when this user has not enabled browser notifications, and validates input', function () {
        $this->org->manager->forceFill(['browser_notifications_enabled' => false])->save();
        $this->actingAs($this->org->manager)->postJson('/profile/notifications/test', ['event' => 'FOLLOWUP_REMINDER'])->assertStatus(422);
        $this->actingAs($this->rahul)->postJson('/profile/notifications/test', ['event' => 'lead_assigned'])->assertJsonValidationErrors('event');
        $this->actingAs($this->rahul)->postJson('/profile/notifications/test', ['event' => 'FOLLOWUP_REMINDER', 'delay' => 999])->assertJsonValidationErrors('delay');
    });
});

describe('subscription replacement', function () {
    test('a changed endpoint replaces the old subscription of the same user', function () {
        $this->actingAs($this->rahul)->postJson('/push-subscriptions', [...pushBody('rahul-laptop-new'), 'replaces' => pushEndpoint('rahul-laptop')])->assertCreated();

        expect(PushSubscription::find($this->laptop->id))->toBeNull()
            ->and($this->rahul->pushSubscriptions()->count())->toBe(2);
    });

    test('replaces cannot remove another user\'s subscription', function () {
        $this->actingAs($this->rahul)->postJson('/push-subscriptions', [...pushBody('rahul-other'), 'replaces' => pushEndpoint('priya-laptop')])->assertCreated();

        expect($this->priya->pushSubscriptions()->count())->toBe(1);
    });
});

describe('notifications:doctor', function () {
    test('flags a stopped scheduler and unclaimed due reminders without printing endpoints', function () {
        Cache::forget(ProductionCheck::SCHEDULER_HEARTBEAT_KEY);
        dueNow();
        $this->travel(5)->minutes();

        $this->artisan('notifications:doctor', ['--user' => $this->rahul->email])
            ->expectsOutputToContain('No heartbeat')
            ->expectsOutputToContain('due reminder(s) not claimed')
            ->doesntExpectOutputToContain('fcm.googleapis.com')
            ->assertFailed();
    });

    test('passes the scheduler and claim checks once reminders are processed', function () {
        pushFakeTransport();
        dueNow();
        Cache::put(ProductionCheck::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String());
        Artisan::call('followups:dispatch-reminders');

        $this->artisan('notifications:doctor')
            ->expectsOutputToContain('Heartbeat 0 min ago')
            ->expectsOutputToContain('No due reminders waiting to be claimed');
    });

    test('can queue a test push for a user', function () {
        Queue::fake();
        $this->artisan('notifications:doctor', ['--user' => $this->rahul->email, '--send-test' => 'reminder'])->assertSuccessful();

        Queue::assertPushed(SendWebPushNotification::class, fn ($job) => $job->userId === $this->rahul->id && $job->payload['event'] === 'FOLLOWUP_REMINDER');
    });
});
