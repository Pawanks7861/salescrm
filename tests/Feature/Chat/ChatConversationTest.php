<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Services\Chat\ConversationService;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = salesOrg();
});

test('chat permissions exist with the expected role defaults', function () {
    $catalogue = array_keys(Permissions::all());

    expect($catalogue)->toContain(Permissions::CHAT_USE, Permissions::PRIORITY_BROADCAST_SEND, Permissions::PRIORITY_BROADCAST_VIEW_HISTORY);

    foreach (['rahul', 'manager', 'admin', 'super'] as $who) {
        expect($this->org->{$who}->hasPermission(Permissions::CHAT_USE))->toBeTrue("{$who} should have chat.use");
    }
    expect($this->org->rahul->hasPermission(Permissions::PRIORITY_BROADCAST_SEND))->toBeFalse()
        ->and($this->org->manager->hasPermission(Permissions::PRIORITY_BROADCAST_SEND))->toBeFalse()
        ->and($this->org->admin->hasPermission(Permissions::PRIORITY_BROADCAST_SEND))->toBeTrue()
        ->and($this->org->admin->hasPermission(Permissions::PRIORITY_BROADCAST_VIEW_HISTORY))->toBeTrue();
});

test('the chat page renders with the sidebar item and badge key', function () {
    $nav = collect($this->actingAs($this->org->rahul)->get('/chat')->assertOk()->inertiaProps('navigation'))
        ->flatMap(fn ($section) => $section['items']);

    expect($nav->firstWhere('label', 'Chat'))->toMatchArray(['href' => '/chat', 'icon' => 'chat', 'badge' => 'chat']);
});

test('users without chat.use cannot open chat and do not see the nav item', function () {
    setPermission($this->org->rahul, Permissions::CHAT_USE, 'deny');

    $this->actingAs($this->org->rahul)->get('/chat')->assertForbidden();
    $this->actingAs($this->org->rahul)->getJson('/chat/conversations')->assertForbidden();

    $labels = collect($this->actingAs($this->org->rahul)->get('/dashboard')->inertiaProps('navigation'))
        ->flatMap(fn ($s) => collect($s['items'])->pluck('label'));
    expect($labels)->not->toContain('Chat');
});

test('starting a chat creates exactly one direct conversation for the pair, from either side', function () {
    $first = $this->actingAs($this->org->rahul)->postJson("/chat/users/{$this->org->priya->id}/conversation")->assertOk()->json('conversation_id');
    $again = $this->actingAs($this->org->rahul)->postJson("/chat/users/{$this->org->priya->id}/conversation")->assertOk()->json('conversation_id');
    $reverse = $this->actingAs($this->org->priya)->postJson("/chat/users/{$this->org->rahul->id}/conversation")->assertOk()->json('conversation_id');

    expect($again)->toBe($first)->and($reverse)->toBe($first)
        ->and(Conversation::count())->toBe(1)
        ->and(ConversationParticipant::where('conversation_id', $first)->count())->toBe(2)
        ->and(Conversation::find($first)->direct_key)->toBe(Conversation::directKey($this->org->rahul->id, $this->org->priya->id));
});

test('a concurrent duplicate insert converges on the same conversation', function () {
    $key = Conversation::directKey($this->org->rahul->id, $this->org->priya->id);
    DB::table('conversations')->insert(['type' => 'direct', 'direct_key' => $key, 'created_at' => now(), 'updated_at' => now()]);

    $conversation = app(ConversationService::class)->findOrCreateDirect($this->org->priya, $this->org->rahul);

    expect(Conversation::count())->toBe(1)
        ->and($conversation->direct_key)->toBe($key)
        ->and($conversation->participants()->count())->toBe(2);
});

test('self chat is rejected', function () {
    $this->actingAs($this->org->rahul)->postJson("/chat/users/{$this->org->rahul->id}/conversation")->assertStatus(422);
    expect(Conversation::count())->toBe(0);
});

test('chat cannot be started with inactive, deleted or chat-disabled users', function () {
    $inactive = User::factory()->salesExecutive()->inactive()->create();
    $deleted = User::factory()->salesExecutive()->create();
    $deleted->delete();
    $noChat = setPermission(User::factory()->salesExecutive()->create(), Permissions::CHAT_USE, 'deny');

    foreach ([$inactive, $noChat] as $target) {
        $this->actingAs($this->org->rahul)->postJson("/chat/users/{$target->id}/conversation")->assertNotFound();
    }
    $this->actingAs($this->org->rahul)->postJson("/chat/users/{$deleted->id}/conversation")->assertNotFound();

    expect(Conversation::count())->toBe(0);
});

test('user search is server-side and returns only active chat users without sensitive fields', function () {
    $inactive = User::factory()->salesExecutive()->inactive()->create(['name' => 'Priyanka Inactive']);
    $noChat = setPermission(User::factory()->salesExecutive()->create(['name' => 'Priyesh NoChat']), Permissions::CHAT_USE, 'deny');
    $granted = setPermission(User::factory()->role('sales_executive')->create(['name' => 'Priyam Granted']), Permissions::CHAT_USE, 'grant');

    $users = collect($this->actingAs($this->org->rahul)->getJson('/chat/users?q=Priy')->assertOk()->json('users'));
    $ids = $users->pluck('id');

    expect($ids)->toContain($this->org->priya->id, $granted->id)
        ->not->toContain($inactive->id, $noChat->id, $this->org->rahul->id);

    $row = $users->firstWhere('id', $this->org->priya->id);
    expect(array_keys($row))->toEqualCanonicalizing(['id', 'name', 'designation', 'role', 'active', 'online', 'last_seen_at'])
        ->and(json_encode($users->all()))->not->toContain('password')->not->toContain('@');
});

test('the conversation list shows only my conversations with preview, presence and unread counts', function () {
    $mine = chatBetween($this->org->rahul, $this->org->priya);
    $others = chatBetween($this->org->manager, $this->org->outsider);
    chatSend($mine, $this->org->priya, 'Hi Rahul');
    chatSend($mine, $this->org->priya, 'Are you free?');
    chatSend($others, $this->org->manager, 'Private between them');

    $list = $this->actingAs($this->org->rahul)->getJson('/chat/conversations')->assertOk();

    expect($list->json('conversations'))->toHaveCount(1)
        ->and($list->json('conversations.0.id'))->toBe($mine->id)
        ->and($list->json('conversations.0.user.name'))->toBe('Priya Patel')
        ->and($list->json('conversations.0.last_message.text'))->toBe('Are you free?')
        ->and($list->json('conversations.0.unread'))->toBe(2)
        ->and($list->json('unread_total'))->toBe(2)
        ->and($list->getContent())->not->toContain('Private between them');
});

test('empty conversations stay out of the list unless opened', function () {
    $conversation = chatBetween($this->org->rahul, $this->org->priya);

    expect($this->actingAs($this->org->priya)->getJson('/chat/conversations')->json('conversations'))->toBe([]);
    expect($this->actingAs($this->org->rahul)->getJson("/chat/conversations?include={$conversation->id}")->json('conversations.0.id'))->toBe($conversation->id);
});

test('unread counts use one grouped query regardless of conversation count', function () {
    foreach ([$this->org->priya, $this->org->manager, $this->org->admin] as $peer) {
        chatSend(chatBetween($this->org->rahul, $peer), $peer, 'ping');
    }

    DB::enableQueryLog();
    $counts = app(ConversationService::class)->unreadCounts($this->org->rahul->id);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect(array_sum($counts))->toBe(3)->and($queries)->toBe(1);
});

test('the chat unread total is shared with every page', function () {
    chatSend(chatBetween($this->org->rahul, $this->org->priya), $this->org->priya, 'hey');

    expect($this->actingAs($this->org->rahul)->get('/dashboard')->inertiaProps('chat.unread'))->toBe(1)
        ->and($this->actingAs($this->org->priya)->get('/dashboard')->inertiaProps('chat.unread'))->toBe(0);
});
