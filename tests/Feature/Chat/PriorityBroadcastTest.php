<?php

use App\Jobs\DeliverPriorityBroadcast;
use App\Jobs\SendWebPushNotification;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PriorityBroadcast;
use App\Models\PriorityBroadcastRecipient;
use App\Models\User;
use App\Notifications\Chat\PriorityBroadcastNotification;
use App\Services\Chat\PriorityBroadcastService;
use App\Support\CrmTime;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->org = salesOrg();
    $this->payload = ['title' => 'Office closed tomorrow', 'message' => "Due to the city holiday the office is closed.\nWork from home."];
});

function priorityNotifications(User $user)
{
    return $user->notifications()->where('type', PriorityBroadcastNotification::class)->get();
}

test('only priority_broadcast.send holders can send; the server enforces it', function () {
    foreach ([$this->org->rahul, $this->org->manager] as $user) {
        $this->actingAs($user)->post('/priority-broadcasts', $this->payload)->assertForbidden();
    }
    expect(PriorityBroadcast::count())->toBe(0);

    setPermission($this->org->manager, Permissions::PRIORITY_BROADCAST_SEND);
    $this->actingAs($this->org->manager)->post('/priority-broadcasts', $this->payload)->assertSessionHasNoErrors()->assertRedirect();
    expect(PriorityBroadcast::count())->toBe(1);
});

test('the dashboard exposes the send button permission only to senders', function () {
    expect($this->actingAs($this->org->admin)->get('/dashboard')->inertiaProps('auth.permissions'))->toContain('priority_broadcast.send')
        ->and($this->actingAs($this->org->rahul)->get('/dashboard')->inertiaProps('auth.permissions'))->not->toContain('priority_broadcast.send');
});

test('validation: title, message and a future expiry', function () {
    $this->actingAs($this->org->admin)->post('/priority-broadcasts', [])->assertSessionHasErrors(['title', 'message']);
    $this->actingAs($this->org->admin)->post('/priority-broadcasts', ['title' => str_repeat('t', 151), 'message' => str_repeat('m', 5001)])->assertSessionHasErrors(['title', 'message']);

    $past = CrmTime::local(now()->subHour())->format('Y-m-d\TH:i');
    $this->actingAs($this->org->admin)->post('/priority-broadcasts', [...$this->payload, 'expires_at' => $past])->assertSessionHasErrors('expires_at');
    $this->actingAs($this->org->admin)->post('/priority-broadcasts', [...$this->payload, 'expires_at' => 'not-a-date'])->assertSessionHasErrors('expires_at');

    expect(PriorityBroadcast::count())->toBe(0);
});

test('expiry is entered in CRM time and stored in UTC', function () {
    $local = CrmTime::local(now()->addDay())->setTime(18, 30);
    $this->actingAs($this->org->admin)->post('/priority-broadcasts', [...$this->payload, 'expires_at' => $local->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();

    expect(PriorityBroadcast::sole()->expires_at->utc()->toDateTimeString())->toBe($local->utc()->toDateTimeString());
});

test('recipients are a snapshot of active users except the sender, delivered by queued chunks after commit', function () {
    Queue::fake();
    $inactive = User::factory()->salesExecutive()->inactive()->create();
    $deleted = User::factory()->salesExecutive()->create();
    $deleted->delete();

    $this->actingAs($this->org->admin)->post('/priority-broadcasts', $this->payload)->assertSessionHasNoErrors();

    $broadcast = PriorityBroadcast::sole();
    $recipientIds = PriorityBroadcastRecipient::where('broadcast_id', $broadcast->id)->pluck('user_id');
    $expected = User::active()->whereKeyNot($this->org->admin->id)->pluck('id');

    expect($recipientIds->sort()->values()->all())->toBe($expected->sort()->values()->all())
        ->and($recipientIds)->not->toContain($this->org->admin->id, $inactive->id, $deleted->id)
        ->and($broadcast->recipients_count)->toBe($expected->count())
        ->and($broadcast->priority)->toBe('urgent');

    Queue::assertPushed(DeliverPriorityBroadcast::class, 1);

    $later = User::factory()->salesExecutive()->create();
    expect(PriorityBroadcastRecipient::where('user_id', $later->id)->exists())->toBeFalse();
});

test('large audiences are split into delivery jobs of 100 users', function () {
    Queue::fake();
    User::factory()->count(205)->salesExecutive()->create();

    app(PriorityBroadcastService::class)->send($this->org->admin, 'Big news', 'Everyone read this', null);

    $total = User::active()->count() - 1;
    Queue::assertPushed(DeliverPriorityBroadcast::class, (int) ceil($total / 100));
    Queue::assertPushed(DeliverPriorityBroadcast::class, fn ($job) => count($job->userIds) <= 100);
});

test('delivery creates one notification and push per recipient and is idempotent on retry', function () {
    pushConfigure();
    pushSubscribe(pushOptIn($this->org->rahul), 'rahul-laptop');
    Queue::fake([SendWebPushNotification::class]);

    $broadcast = app(PriorityBroadcastService::class)->send($this->org->admin, 'Fire drill', 'Assemble at gate 2 at 4 pm.', null);

    $note = priorityNotifications($this->org->rahul)->sole();
    expect($note->data)->toMatchArray([
        'category' => 'priority',
        'event' => 'priority_broadcast',
        'priority_broadcast_id' => $broadcast->id,
        'url' => "/priority-broadcasts/{$broadcast->id}",
    ])->and(priorityNotifications($this->org->admin))->toHaveCount(0);

    Queue::assertPushed(SendWebPushNotification::class, fn ($job) => $job->userId === $this->org->rahul->id
        && $job->payload['event'] === 'PRIORITY_BROADCAST'
        && $job->payload['title'] === 'Urgent: Fire drill');

    expect(PriorityBroadcastRecipient::whereNull('delivered_at')->count())->toBe(0);

    // A retried job (or a duplicate dispatch) must not notify anyone twice.
    PriorityBroadcastRecipient::query()->update(['delivered_at' => null]);
    (new DeliverPriorityBroadcast($broadcast->id, PriorityBroadcastRecipient::pluck('user_id')->all()))->handle(app(PriorityBroadcastService::class));

    expect(priorityNotifications($this->org->rahul))->toHaveCount(1)
        ->and(DB::table('notifications')->where('type', PriorityBroadcastNotification::class)->count())->toBe($broadcast->recipients_count);
});

test('the persistent banner shows until acknowledged', function () {
    $broadcast = app(PriorityBroadcastService::class)->send($this->org->admin, 'Banner test', 'Read me', null);

    $alerts = $this->actingAs($this->org->rahul)->get('/dashboard')->inertiaProps('priority');
    expect($alerts)->toHaveCount(1)->and($alerts[0])->toMatchArray(['id' => $broadcast->id, 'title' => 'Banner test', 'message' => 'Read me', 'read' => false]);

    expect($this->actingAs($this->org->admin)->get('/dashboard')->inertiaProps('priority'))->toBe([]);

    $this->actingAs($this->org->rahul)->postJson("/priority-broadcasts/{$broadcast->id}/acknowledge")
        ->assertOk()->assertJson(['notifications_unread' => 0]);
    expect($this->actingAs($this->org->rahul)->get('/dashboard')->inertiaProps('priority'))->toBe([])
        ->and($this->actingAs($this->org->rahul)->postJson('/presence/heartbeat')->json('priority'))->toBe([]);
});

test('expired broadcasts leave the banner but stay in history', function () {
    $broadcast = app(PriorityBroadcastService::class)->send($this->org->admin, 'Short lived', 'Soon gone', now()->addMinutes(5));
    expect($this->actingAs($this->org->rahul)->postJson('/presence/heartbeat')->json('priority'))->toHaveCount(1);

    $this->travel(10)->minutes();

    expect($this->actingAs($this->org->rahul)->postJson('/presence/heartbeat')->json('priority'))->toBe([]);
    $rows = $this->actingAs($this->org->admin)->get('/priority-broadcasts')->assertOk()->inertiaProps('broadcasts.data');
    expect($rows[0])->toMatchArray(['id' => $broadcast->id, 'expired' => true]);
});

test('acknowledgement is recipient-only, sets read and acknowledged, and is audited once', function () {
    $broadcast = app(PriorityBroadcastService::class)->send($this->org->admin, 'Ack test', 'Please ack', null);

    $this->actingAs($this->org->admin)->postJson("/priority-broadcasts/{$broadcast->id}/acknowledge")->assertForbidden();
    $this->actingAs($this->org->rahul)->postJson('/priority-broadcasts/999999/acknowledge')->assertNotFound();

    $this->actingAs($this->org->rahul)->postJson("/priority-broadcasts/{$broadcast->id}/acknowledge", ['user_id' => $this->org->priya->id])->assertOk();
    $this->actingAs($this->org->rahul)->postJson("/priority-broadcasts/{$broadcast->id}/acknowledge")->assertOk();

    $rahul = PriorityBroadcastRecipient::where('broadcast_id', $broadcast->id)->where('user_id', $this->org->rahul->id)->sole();
    $priya = PriorityBroadcastRecipient::where('broadcast_id', $broadcast->id)->where('user_id', $this->org->priya->id)->sole();

    expect($rahul->acknowledged_at)->not->toBeNull()->and($rahul->read_at)->not->toBeNull()
        ->and($priya->acknowledged_at)->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'PRIORITY_BROADCAST_ACKNOWLEDGED')->count())->toBe(1)
        ->and(priorityNotifications($this->org->rahul)->sole()->read_at)->not->toBeNull();
});

test('sending is audited without recipient personal data', function () {
    $this->actingAs($this->org->admin)->post('/priority-broadcasts', $this->payload);

    $log = DB::table('audit_logs')->where('action', 'PRIORITY_BROADCAST_SENT')->sole();
    expect($log->user_id)->toBe($this->org->admin->id)
        ->and($log->new_values_json)->toContain('Office closed tomorrow')
        ->and($log->new_values_json)->not->toContain($this->org->rahul->email);
});

test('history is limited to view_history holders and shows read / acknowledged counts', function () {
    $broadcast = app(PriorityBroadcastService::class)->send($this->org->admin, 'Counts', 'x', null);
    app(PriorityBroadcastService::class)->acknowledge(PriorityBroadcastRecipient::where('user_id', $this->org->rahul->id)->sole());

    $this->actingAs($this->org->rahul)->get('/priority-broadcasts')->assertForbidden();

    $row = $this->actingAs($this->org->admin)->get('/priority-broadcasts')->assertOk()->inertiaProps('broadcasts.data.0');
    expect($row)->toMatchArray(['id' => $broadcast->id, 'sender' => 'Anita Admin', 'read_count' => 1, 'acknowledged_count' => 1, 'expired' => false])
        ->and($row['recipients_count'])->toBe($broadcast->recipients_count);

    $this->actingAs($this->org->super)->get('/priority-broadcasts')->assertOk();
});

test('the detail page shows the message to recipients and the recipient list only to history viewers', function () {
    $broadcast = app(PriorityBroadcastService::class)->send($this->org->admin, 'Detail', 'Body text', null);

    $recipientView = $this->actingAs($this->org->rahul)->get("/priority-broadcasts/{$broadcast->id}")->assertOk();
    expect($recipientView->inertiaProps('broadcast.message'))->toBe('Body text')
        ->and($recipientView->inertiaProps('recipients'))->toBeNull()
        ->and($recipientView->inertiaProps('recipient.read_at'))->not->toBeNull();

    $adminView = $this->actingAs($this->org->admin)->get("/priority-broadcasts/{$broadcast->id}")->assertOk();
    expect($adminView->inertiaProps('recipients.total'))->toBe($broadcast->recipients_count);

    $outsider = User::factory()->salesExecutive()->create();
    $this->actingAs($outsider)->get("/priority-broadcasts/{$broadcast->id}")->assertForbidden();
});

test('the bell opens the broadcast page', function () {
    $broadcast = app(PriorityBroadcastService::class)->send($this->org->admin, 'Bell', 'x', null);
    $note = priorityNotifications($this->org->rahul)->sole();

    $this->actingAs($this->org->rahul)->get("/notifications/{$note->id}/open")->assertRedirect("/priority-broadcasts/{$broadcast->id}");
});

test('broadcasts never use chat conversations', function () {
    app(PriorityBroadcastService::class)->send($this->org->admin, 'Separate', 'x', null);

    expect(Conversation::count())->toBe(0)->and(Message::count())->toBe(0);
});

test('history is never auto-deleted by any scheduled task', function () {
    $this->artisan('schedule:list')->assertSuccessful()->doesntExpectOutputToContain('priority');
});
