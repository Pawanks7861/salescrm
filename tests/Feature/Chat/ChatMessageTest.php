<?php

use App\Models\Attachment;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->org = salesOrg();
    $this->conversation = chatBetween($this->org->rahul, $this->org->priya);
    $this->url = "/chat/conversations/{$this->conversation->id}/messages";
});

test('a participant can send a text message', function () {
    $response = $this->actingAs($this->org->rahul)->postJson($this->url, ['message' => "  Hello Priya\nSecond line  "])->assertCreated();

    expect($response->json('message'))->toMatchArray(['body' => "Hello Priya\nSecond line", 'mine' => true, 'deleted' => false, 'edited' => false])
        ->and(Message::sole()->sender_id)->toBe($this->org->rahul->id)
        ->and($this->conversation->fresh()->last_message_id)->toBe(Message::sole()->id);
});

test('an empty message without attachments is rejected', function () {
    $this->actingAs($this->org->rahul)->postJson($this->url, ['message' => ''])->assertUnprocessable()->assertJsonValidationErrors('message');
    $this->actingAs($this->org->rahul)->postJson($this->url, ['message' => "   \n  "])->assertUnprocessable()->assertJsonValidationErrors('message');
    expect(Message::count())->toBe(0);
});

test('messages longer than the limit are rejected', function () {
    $this->actingAs($this->org->rahul)->postJson($this->url, ['message' => str_repeat('a', 5001)])->assertUnprocessable()->assertJsonValidationErrors('message');
});

test('message text is stored and returned as plain text, never interpreted', function () {
    $payload = '<script>alert(1)</script><img src=x onerror=alert(2)>';
    $this->actingAs($this->org->rahul)->postJson($this->url, ['message' => $payload])->assertCreated();

    $body = $this->actingAs($this->org->priya)->getJson($this->url)->json('messages.0.body');
    expect($body)->toBe($payload)->and(Message::sole()->body)->toBe($payload);
});

test('attachments are stored privately under a random name and listed without storage paths', function () {
    Storage::fake('local');

    $response = $this->actingAs($this->org->rahul)->post($this->url, [
        'attachments' => [UploadedFile::fake()->create('../../quote.pdf', 120, 'application/pdf')],
    ], ['Accept' => 'application/json'])->assertCreated();

    $attachment = Attachment::sole();
    expect($attachment->attachable_type)->toBe(Message::class)
        ->and($attachment->disk)->toBe('local')
        ->and($attachment->path)->toStartWith("chat/{$this->conversation->id}/")
        ->and($attachment->stored_name)->not->toContain('quote')
        ->and($attachment->original_name)->toBe('quote.pdf');
    Storage::disk('local')->assertExists($attachment->path);

    $json = $response->json('message.attachments.0');
    expect($json)->toMatchArray(['name' => 'quote.pdf', 'is_image' => false, 'url' => "/chat/attachments/{$attachment->id}"])
        ->and(json_encode($response->json()))->not->toContain($attachment->stored_name)->not->toContain('chat/'.$this->conversation->id.'/');
});

test('an attachment-only message is allowed and images get a preview url', function () {
    Storage::fake('local');

    $response = $this->actingAs($this->org->rahul)->post($this->url, [
        'attachments' => [UploadedFile::fake()->image('site.png', 40, 40)],
    ], ['Accept' => 'application/json'])->assertCreated();

    expect($response->json('message.body'))->toBeNull()
        ->and($response->json('message.attachments.0.is_image'))->toBeTrue()
        ->and($response->json('message.attachments.0.preview_url'))->toContain('inline=1');
});

test('disallowed file types and oversized files are rejected', function () {
    Storage::fake('local');

    $this->actingAs($this->org->rahul)->post($this->url, ['attachments' => [UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')]], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
    $this->actingAs($this->org->rahul)->post($this->url, ['attachments' => [UploadedFile::fake()->create('page.html', 10, 'text/html')]], ['Accept' => 'application/json'])
        ->assertUnprocessable();
    $this->actingAs($this->org->rahul)->post($this->url, ['attachments' => [UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf')]], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('attachments.0');

    expect(Message::count())->toBe(0)->and(Attachment::count())->toBe(0);
});

test('the attachment size limit is configurable', function () {
    Storage::fake('local');
    config(['crm.chat.max_attachment_kb' => 50]);

    $this->actingAs($this->org->rahul)->post($this->url, ['attachments' => [UploadedFile::fake()->create('a.pdf', 60, 'application/pdf')]], ['Accept' => 'application/json'])
        ->assertUnprocessable();
});

test('a reply must reference a message in the same conversation', function () {
    $own = chatSend($this->conversation, $this->org->priya, 'Original');
    $foreign = chatSend(chatBetween($this->org->manager, $this->org->outsider), $this->org->manager, 'Elsewhere');

    $this->actingAs($this->org->rahul)->postJson($this->url, ['message' => 'Bad reply', 'reply_to_message_id' => $foreign->id])
        ->assertUnprocessable()->assertJsonValidationErrors('reply_to_message_id');

    $reply = $this->actingAs($this->org->rahul)->postJson($this->url, ['message' => 'Good reply', 'reply_to_message_id' => $own->id])->assertCreated();
    expect($reply->json('message.reply_to'))->toMatchArray(['id' => $own->id, 'preview' => 'Original'])
        ->and($reply->getContent())->not->toContain('Elsewhere');
});

test('the sender can edit their own text message and it is marked edited', function () {
    $message = chatSend($this->conversation, $this->org->rahul, 'Typo mesage');

    $this->actingAs($this->org->rahul)->putJson("/chat/messages/{$message->id}", ['message' => 'Typo message'])
        ->assertOk()->assertJsonPath('message.body', 'Typo message')->assertJsonPath('message.edited', true);

    expect($message->fresh()->edited_at)->not->toBeNull();
});

test('nobody else can edit or delete a message', function () {
    $message = chatSend($this->conversation, $this->org->rahul, 'Mine');

    foreach ([$this->org->priya, $this->org->admin, $this->org->super] as $user) {
        $this->actingAs($user)->putJson("/chat/messages/{$message->id}", ['message' => 'Hacked'])->assertForbidden();
        $this->actingAs($user)->deleteJson("/chat/messages/{$message->id}")->assertForbidden();
    }

    expect($message->fresh()->body)->toBe('Mine')->and($message->fresh()->trashed())->toBeFalse();
});

test('deleting soft-deletes and shows the deleted placeholder without text or attachments', function () {
    Storage::fake('local');
    $message = chatSend($this->conversation, $this->org->rahul, 'Secret', [UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')]);
    $attachment = $message->attachments->sole();

    $this->actingAs($this->org->rahul)->deleteJson("/chat/messages/{$message->id}")->assertOk()->assertJsonPath('message.deleted', true);

    expect(Message::withTrashed()->find($message->id)->trashed())->toBeTrue();
    Storage::disk('local')->assertExists($attachment->path);

    $row = $this->actingAs($this->org->priya)->getJson($this->url)->json('messages.0');
    expect($row)->toMatchArray(['deleted' => true, 'body' => null, 'attachments' => []]);
    $this->actingAs($this->org->priya)->get("/chat/attachments/{$attachment->id}")->assertNotFound();

    expect($this->actingAs($this->org->priya)->getJson('/chat/conversations')->json('conversations.0.last_message.text'))->toBe('This message was deleted.');
    $this->actingAs($this->org->rahul)->putJson("/chat/messages/{$message->id}", ['message' => 'again'])->assertNotFound();
    expect(Message::withTrashed()->find($message->id)->body)->toBe('Secret');
});

test('opening a conversation marks it read and the sender sees a read status', function () {
    $first = chatSend($this->conversation, $this->org->rahul, 'One');
    $second = chatSend($this->conversation, $this->org->rahul, 'Two');

    $before = $this->actingAs($this->org->rahul)->getJson($this->url)->json('participant.last_read_message_id');
    expect($before)->toBeNull();

    $this->actingAs($this->org->priya)->postJson("/chat/conversations/{$this->conversation->id}/read")
        ->assertOk()->assertJson(['last_read_message_id' => $second->id, 'unread_total' => 0, 'notifications_unread' => 0]);

    expect($this->actingAs($this->org->rahul)->getJson($this->url)->json('participant.last_read_message_id'))->toBe($second->id);
});

test('the read pointer never moves backwards', function () {
    chatSend($this->conversation, $this->org->rahul, 'One');
    $this->actingAs($this->org->priya)->postJson("/chat/conversations/{$this->conversation->id}/read");
    $pointer = $this->conversation->participantFor($this->org->priya->id)->last_read_message_id;

    $this->conversation->participants()->where('user_id', $this->org->priya->id)->update(['last_read_message_id' => $pointer + 50]);
    $this->actingAs($this->org->priya)->postJson("/chat/conversations/{$this->conversation->id}/read");

    expect($this->conversation->participantFor($this->org->priya->id)->last_read_message_id)->toBe($pointer + 50);
});

test('the sender\'s own messages never count as unread for them', function () {
    chatSend($this->conversation, $this->org->rahul, 'Mine');

    expect($this->actingAs($this->org->rahul)->getJson('/chat/conversations')->json('unread_total'))->toBe(0)
        ->and($this->actingAs($this->org->priya)->getJson('/chat/conversations')->json('unread_total'))->toBe(1);
});

test('messages paginate by cursor: latest page, older history and live sync', function () {
    $ids = collect(range(1, 45))->map(fn ($i) => chatSend($this->conversation, $i % 2 ? $this->org->rahul : $this->org->priya, "m{$i}")->id);

    $latest = $this->actingAs($this->org->rahul)->getJson($this->url)->assertOk();
    expect($latest->json('messages'))->toHaveCount(40)
        ->and($latest->json('messages.0.body'))->toBe('m6')
        ->and($latest->json('messages.39.body'))->toBe('m45')
        ->and($latest->json('has_more'))->toBeTrue();

    $older = $this->actingAs($this->org->rahul)->getJson($this->url.'?before='.$ids[5])->assertOk();
    expect(collect($older->json('messages'))->pluck('body')->all())->toBe(['m1', 'm2', 'm3', 'm4', 'm5'])
        ->and($older->json('has_more'))->toBeFalse();

    $new = chatSend($this->conversation, $this->org->priya, 'fresh');
    $sync = $this->actingAs($this->org->rahul)->getJson($this->url.'?after='.$ids->last())->assertOk();
    expect(collect($sync->json('messages'))->pluck('id')->all())->toBe([$new->id]);
});

test('live sync reports edits and deletions since the last sync', function () {
    $message = chatSend($this->conversation, $this->org->rahul, 'Original');
    $since = now()->subSecond()->toIso8601String();

    $this->travel(5)->seconds();
    $this->actingAs($this->org->rahul)->putJson("/chat/messages/{$message->id}", ['message' => 'Edited']);

    $changed = $this->actingAs($this->org->priya)->getJson($this->url."?after={$message->id}&since=".urlencode($since))->json('changed');
    expect($changed)->toHaveCount(1)->and($changed[0])->toMatchArray(['id' => $message->id, 'body' => 'Edited', 'edited' => true]);
});

test('typing indicator is visible to the other participant only briefly', function () {
    $this->actingAs($this->org->rahul)->postJson("/chat/conversations/{$this->conversation->id}/typing")->assertOk();

    expect($this->actingAs($this->org->priya)->getJson($this->url)->json('participant.typing'))->toBeTrue()
        ->and($this->actingAs($this->org->rahul)->getJson($this->url)->json('participant.typing'))->toBeFalse();

    $this->travel(10)->seconds();
    expect($this->actingAs($this->org->priya)->getJson($this->url)->json('participant.typing'))->toBeFalse();
});

test('messages to a deactivated user are refused but history stays readable', function () {
    chatSend($this->conversation, $this->org->priya, 'Before leaving');
    $this->org->priya->forceFill(['is_active' => false])->save();

    $this->actingAs($this->org->rahul)->postJson($this->url, ['message' => 'Still there?'])->assertStatus(422);
    $page = $this->actingAs($this->org->rahul)->getJson($this->url)->assertOk();

    expect($page->json('can_send'))->toBeFalse()
        ->and($page->json('messages.0.body'))->toBe('Before leaving')
        ->and($page->json('participant.user.active'))->toBeFalse();
});

test('a super admin counterpart can be replied to from the live thread', function () {
    $super = User::factory()->superAdmin()->create();
    $conversation = chatBetween($super, $this->org->rahul);
    chatSend($conversation, $super, 'Hello from the top');

    $page = $this->actingAs($this->org->rahul)->getJson("/chat/conversations/{$conversation->id}/messages")->assertOk();

    expect($page->json('can_send'))->toBeTrue();
    $this->actingAs($this->org->rahul)
        ->postJson("/chat/conversations/{$conversation->id}/messages", ['message' => 'Hi back'])
        ->assertCreated();
});

test('emoji sequences survive while control characters are stripped', function () {
    $this->actingAs($this->org->rahul)->postJson($this->url, ['message' => "Team 👨‍👩‍👧 done\x07\x00 ✅"])->assertCreated();

    expect(Message::sole()->body)->toBe('Team 👨‍👩‍👧 done ✅');
});
