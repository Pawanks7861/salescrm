<?php

use App\Jobs\SendFcmNotification;
use App\Jobs\SendWebPushNotification;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Chat\ChatMessageNotification;
use App\Services\Chat\MessageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    pushConfigure();
    $this->org = salesOrg();
    $this->priya = pushOptIn($this->org->priya);
    pushSubscribe($this->priya, 'priya-laptop');
    $this->conversation = chatBetween($this->org->rahul, $this->priya);
});

function chatNotifications($user)
{
    return $user->notifications()->where('type', ChatMessageNotification::class)->get();
}

test('a message creates exactly one database notification and one push for the recipient only', function () {
    Queue::fake([SendWebPushNotification::class, SendFcmNotification::class]);
    $message = chatSend($this->conversation, $this->org->rahul, 'Can you call the Mehta lead today?');

    $notification = chatNotifications($this->priya)->sole();
    expect($notification->id)->toBe(ChatMessageNotification::idFor($message))
        ->and($notification->data)->toMatchArray([
            'category' => 'chat',
            'event' => 'chat_message',
            'sender_id' => $this->org->rahul->id,
            'conversation_id' => $this->conversation->id,
            'message_id' => $message->id,
            'preview' => 'Can you call the Mehta lead today?',
            'url' => "/chat/{$this->conversation->id}",
        ])
        ->and($notification->data['message'])->toBe('Rahul Sharma sent you a message: Can you call the Mehta lead today?')
        ->and(chatNotifications($this->org->rahul))->toHaveCount(0);

    Queue::assertPushed(SendWebPushNotification::class, 1);
    Queue::assertPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => $job->userId === $this->priya->id
        && $job->payload['event'] === 'CHAT_MESSAGE'
        && $job->payload['title'] === 'Rahul Sharma sent you a message'
        && $job->payload['body'] === 'Can you call the Mehta lead today?'
        && $job->payload['url'] === "/notifications/{$notification->id}/open");
});

test('the preview is truncated and attachments are described, never included', function () {
    Queue::fake([SendWebPushNotification::class, SendFcmNotification::class]);
    Storage::fake('local');

    chatSend($this->conversation, $this->org->rahul, str_repeat('long text ', 40));
    $long = chatNotifications($this->priya)->last();
    expect(mb_strlen($long->data['preview']))->toBeLessThanOrEqual(123);

    chatSend($this->conversation, $this->org->rahul, null, [UploadedFile::fake()->create('salary.pdf', 5, 'application/pdf')]);
    $file = $this->priya->notifications()->where('data->preview', null)->where('type', ChatMessageNotification::class)->sole();
    expect($file->data['message'])->toBe('Rahul Sharma sent you an attachment.')
        ->and(json_encode($file->data))->not->toContain('salary');

    Queue::assertPushed(SendWebPushNotification::class, fn ($job) => $job->payload['body'] === 'Rahul Sharma sent you an attachment.');
});

test('a retried notification for the same message cannot create a duplicate', function () {
    Queue::fake([SendWebPushNotification::class, SendFcmNotification::class]);
    $message = chatSend($this->conversation, $this->org->rahul, 'Once');

    try {
        $this->priya->notify(new ChatMessageNotification($message, $this->org->rahul));
    } catch (\Throwable) {
        // unique primary key rejects the duplicate
    }

    expect(chatNotifications($this->priya))->toHaveCount(1);
});

test('no browser push while the recipient is viewing the conversation, but the message is still recorded', function () {
    Queue::fake([SendWebPushNotification::class, SendFcmNotification::class]);
    app(MessageService::class)->markViewing($this->conversation, $this->priya);

    chatSend($this->conversation, $this->org->rahul, 'You are looking at this');

    expect(chatNotifications($this->priya))->toHaveCount(1);
    Queue::assertNotPushed(SendWebPushNotification::class);
});

test('the viewing flag comes from the open conversation sync and expires', function () {
    $this->actingAs($this->priya)->getJson("/chat/conversations/{$this->conversation->id}/messages?viewing=1")->assertOk();
    expect(app(MessageService::class)->isViewing($this->conversation->id, $this->priya->id))->toBeTrue();

    $this->travel(30)->seconds();
    expect(app(MessageService::class)->isViewing($this->conversation->id, $this->priya->id))->toBeFalse();
});

test('marking the conversation read also clears its chat notifications from the bell', function () {
    Queue::fake([SendWebPushNotification::class, SendFcmNotification::class]);
    chatSend($this->conversation, $this->org->rahul, 'One');
    chatSend($this->conversation, $this->org->rahul, 'Two');
    expect($this->priya->unreadNotifications()->count())->toBe(2);

    $this->actingAs($this->priya)->postJson("/chat/conversations/{$this->conversation->id}/read")->assertOk();

    expect($this->priya->unreadNotifications()->count())->toBe(0);
});

test('the bell opens the conversation for participants', function () {
    Queue::fake([SendWebPushNotification::class, SendFcmNotification::class]);
    chatSend($this->conversation, $this->org->rahul, 'Open me');
    $notification = chatNotifications($this->priya)->sole();

    $row = collect($this->actingAs($this->priya)->getJson('/notifications/recent')->json('data'))->firstWhere('id', $notification->id);
    expect($row)->toMatchArray(['stale' => false, 'target' => "/chat/{$this->conversation->id}", 'event' => 'chat_message']);

    $this->actingAs($this->priya)->get("/notifications/{$notification->id}/open")->assertRedirect("/chat/{$this->conversation->id}");
    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('deleting a message removes its pending chat notification', function () {
    Queue::fake([SendWebPushNotification::class, SendFcmNotification::class]);
    $message = chatSend($this->conversation, $this->org->rahul, 'Oops wrong person');

    $this->actingAs($this->org->rahul)->deleteJson("/chat/messages/{$message->id}")->assertOk();

    expect(chatNotifications($this->priya))->toHaveCount(0);
});

test('the bell row is written in-request while outbound pushes wait on the queue', function () {
    config(['queue.default' => 'database']);

    chatSend($this->conversation, $this->org->rahul, 'Fast send');

    $jobs = DB::table('jobs')->pluck('payload')->map(fn ($payload) => json_decode($payload, true)['displayName']);
    expect(chatNotifications($this->priya))->toHaveCount(1)
        ->and($jobs->all())->toBe([ChatMessageNotification::class, ChatMessageNotification::class]);
});

test('a message deleted before its queued push runs is never pushed', function () {
    Queue::fake([SendWebPushNotification::class, SendFcmNotification::class]);
    $message = chatSend($this->conversation, $this->org->rahul, 'Oops');
    $notification = new ChatMessageNotification($message, $this->org->rahul);

    expect($notification->shouldSend($this->priya, WebPushChannel::class))->toBeTrue();

    $message->delete();

    expect($notification->shouldSend($this->priya, WebPushChannel::class))->toBeFalse()
        ->and($notification->shouldSend($this->priya, 'database'))->toBeTrue();
});

test('a deactivated recipient is not notified and the message is still saved', function () {
    $this->priya->forceFill(['is_active' => false])->save();

    $message = chatSend($this->conversation, $this->org->rahul, 'Still saved');

    expect($message->exists)->toBeTrue()->and(chatNotifications($this->priya))->toHaveCount(0);
});
