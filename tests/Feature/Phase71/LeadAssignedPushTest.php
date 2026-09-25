<?php

use App\Enums\AssignmentType;
use App\Jobs\SendWebPushNotification;
use App\Models\Lead;
use App\Notifications\Leads\LeadAssignedNotification;
use App\Services\Leads\LeadAssignmentService;
use App\Services\Notifications\PushTransport;
use App\Services\Notifications\WebPushService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    pushConfigure();
    $this->org = salesOrg();
    $this->rahul = pushOptIn($this->org->rahul);
    pushSubscribe($this->rahul, 'rahul-laptop');
    pushSubscribe($this->rahul, 'rahul-phone');
});

function createLeadFor(TestCase $test, $actor, $owner, array $extra = []): Lead
{
    $test->actingAs($actor)
        ->post('/leads', leadPayload(['first_name' => 'Amit', 'last_name' => 'Desai', 'assigned_to' => $owner->id, ...$extra]))
        ->assertSessionHasNoErrors()->assertRedirect();

    return Lead::where('first_name', 'Amit')->latest('id')->firstOrFail();
}

function assignedNotifications($user)
{
    return $user->notifications()->where('type', LeadAssignedNotification::class)->get();
}

test('a lead assigned to Rahul creates one notification and exactly one push job; Priya gets nothing', function () {
    Queue::fake();
    $lead = createLeadFor($this, $this->org->manager, $this->rahul);

    $notification = assignedNotifications($this->rahul)->sole();
    expect($notification->data)->toMatchArray(['event' => 'lead_assigned', 'lead_id' => $lead->id])
        ->and($notification->data['message'])->toBe("New lead assigned to you: Amit Desai ({$lead->lead_number})");

    Queue::assertPushed(SendWebPushNotification::class, 1);
    Queue::assertPushed(SendWebPushNotification::class, function (SendWebPushNotification $job) use ($notification) {
        return $job->userId === $this->rahul->id
            && $job->payload['id'] === $notification->id
            && $job->payload['title'] === 'New Lead Assigned'
            && $job->payload['body'] === 'Amit Desai has been assigned to you.'
            && $job->payload['url'] === "/notifications/{$notification->id}/open";
    });

    expect($this->org->priya->notifications()->count())->toBe(0);
});

test('the push payload carries no phone number or email', function () {
    Queue::fake();
    createLeadFor($this, $this->org->manager, $this->rahul, ['phone' => '9812345678', 'email' => 'amit@example.com']);

    Queue::assertPushed(SendWebPushNotification::class, fn ($job) => ! str_contains(json_encode($job->payload), '9812345678')
        && ! str_contains(json_encode($job->payload), 'amit@example.com'));
});

test('re-running the same assignment does not notify or push again', function () {
    Queue::fake();
    $lead = createLeadFor($this, $this->org->manager, $this->rahul);

    $changed = app(LeadAssignmentService::class)->assign($lead->fresh(), $this->rahul, AssignmentType::Manual, $this->org->admin);

    expect($changed)->toBeFalse()
        ->and(assignedNotifications($this->rahul))->toHaveCount(1);
    Queue::assertPushed(SendWebPushNotification::class, 1);
});

test('the push job is unique per notification id', function () {
    Queue::fake();
    SendWebPushNotification::dispatch($this->rahul->id, ['id' => 'same-id', 'event' => 'NEW_LEAD_ASSIGNED', 'title' => 'T', 'body' => 'B', 'url' => '/x']);
    SendWebPushNotification::dispatch($this->rahul->id, ['id' => 'same-id', 'event' => 'NEW_LEAD_ASSIGNED', 'title' => 'T', 'body' => 'B', 'url' => '/x']);

    Queue::assertPushed(SendWebPushNotification::class, 1);
});

test('the job delivers once to each of Rahul\'s browsers with the default icon and sound flag', function () {
    $transport = pushFakeTransport();
    createLeadFor($this, $this->org->manager, $this->rahul);

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['subscription_ids'])->toHaveCount(2)
        ->and($transport->sent[0]['payload'])->toMatchArray([
            'event' => 'NEW_LEAD_ASSIGNED',
            'icon' => '/images/notification-icon.png',
            'sound' => true,
        ]);
});

test('reassignment is announced as a reassignment', function () {
    Queue::fake();
    $lead = createLeadFor($this, $this->org->admin, $this->org->priya);
    $this->actingAs($this->org->admin)->post("/leads/{$lead->id}/assign", ['assigned_to' => $this->rahul->id])->assertRedirect();

    expect(assignedNotifications($this->rahul)->sole()->data['message'])->toStartWith('Lead reassigned to you:');
});

test('self-assignment notifies nobody', function () {
    Queue::fake();
    $this->actingAs($this->rahul)->post('/leads', leadPayload(['first_name' => 'Own']))->assertRedirect();

    expect(assignedNotifications($this->rahul))->toHaveCount(0);
    Queue::assertNotPushed(SendWebPushNotification::class);
});

test('global switch OFF keeps the in-app notification but sends no push', function () {
    Queue::fake();
    app(SettingService::class)->put('notifications.browser_enabled', false);

    createLeadFor($this, $this->org->manager, $this->rahul);

    expect(assignedNotifications($this->rahul))->toHaveCount(1);
    Queue::assertNotPushed(SendWebPushNotification::class);
});

test('user preference OFF keeps the in-app notification but sends no push', function () {
    Queue::fake();
    $this->rahul->forceFill(['browser_notifications_enabled' => false])->save();

    createLeadFor($this, $this->org->manager, $this->rahul);

    expect(assignedNotifications($this->rahul))->toHaveCount(1);
    Queue::assertNotPushed(SendWebPushNotification::class);
});

test('a job queued before the user opted out sends nothing', function () {
    $transport = pushFakeTransport();
    $this->rahul->forceFill(['browser_notifications_enabled' => false])->save();

    (new SendWebPushNotification($this->rahul->id, ['id' => 'x', 'event' => 'NEW_LEAD_ASSIGNED', 'title' => 'T', 'body' => 'B', 'url' => '/x']))
        ->handle(app(WebPushService::class));

    expect($transport->sent)->toBe([]);
});

test('hiding names on the lock screen removes the customer name from the push', function () {
    Queue::fake();
    app(SettingService::class)->put('notifications.browser_show_names', false);

    createLeadFor($this, $this->org->manager, $this->rahul);

    Queue::assertPushed(SendWebPushNotification::class, fn ($job) => $job->payload['body'] === 'A new lead has been assigned to you.');
});

test('a push failure never fails lead creation', function () {
    app()->instance(PushTransport::class, new class implements PushTransport
    {
        public function send(iterable $subscriptions, string $payload): array
        {
            throw new RuntimeException('push service down');
        }
    });

    $lead = createLeadFor($this, $this->org->manager, $this->rahul);

    expect($lead->assigned_to)->toBe($this->rahul->id)
        ->and(assignedNotifications($this->rahul))->toHaveCount(1);
});

test('the push click URL re-checks ownership and lead visibility', function () {
    Queue::fake();
    $lead = createLeadFor($this, $this->org->manager, $this->rahul);
    $id = assignedNotifications($this->rahul)->sole()->id;

    $this->actingAs($this->org->priya)->get("/notifications/{$id}/open")->assertNotFound();
    $this->actingAs($this->rahul)->get("/notifications/{$id}/open")->assertRedirect("/leads/{$lead->id}");

    $this->actingAs($this->org->admin)->post("/leads/{$lead->id}/assign", ['assigned_to' => $this->org->outsider->id])->assertSessionHasNoErrors();
    $this->actingAs($this->rahul)->get("/notifications/{$id}/open")->assertRedirect(route('notifications.index'));
});
