<?php

use App\Models\User;
use App\Services\Chat\PresenceService;

beforeEach(function () {
    $this->org = salesOrg();
});

test('the heartbeat records last_seen_at for the caller only', function () {
    $this->actingAs($this->org->rahul)->postJson('/presence/heartbeat')->assertOk()->assertJsonStructure(['chat_unread', 'priority']);

    expect($this->org->rahul->fresh()->last_seen_at)->not->toBeNull()
        ->and($this->org->priya->fresh()->last_seen_at)->toBeNull();
});

test('heartbeat writes are throttled to once a minute', function () {
    $this->actingAs($this->org->rahul)->postJson('/presence/heartbeat');
    $first = $this->org->rahul->fresh()->last_seen_at;

    $this->travel(20)->seconds();
    $this->actingAs($this->org->rahul->fresh())->postJson('/presence/heartbeat');
    expect($this->org->rahul->fresh()->last_seen_at->equalTo($first))->toBeTrue();

    $this->travel(50)->seconds();
    $this->actingAs($this->org->rahul->fresh())->postJson('/presence/heartbeat');
    expect($this->org->rahul->fresh()->last_seen_at->gt($first))->toBeTrue();
});

test('online means seen within two minutes; otherwise last seen is reported', function () {
    $this->org->priya->forceFill(['last_seen_at' => now()->subSeconds(90)])->save();
    $users = collect($this->actingAs($this->org->rahul)->getJson('/chat/users?q=Priya')->json('users'));
    expect($users->firstWhere('id', $this->org->priya->id)['online'])->toBeTrue();

    $this->org->priya->forceFill(['last_seen_at' => now()->subMinutes(3)])->save();
    $row = collect($this->actingAs($this->org->rahul)->getJson('/chat/users?q=Priya')->json('users'))->firstWhere('id', $this->org->priya->id);
    expect($row['online'])->toBeFalse()->and($row['last_seen_at'])->not->toBeNull();
});

test('presence helpers', function () {
    expect(PresenceService::isOnline(null))->toBeFalse()
        ->and(PresenceService::isOnline(now()->subSeconds(30)))->toBeTrue()
        ->and(PresenceService::isOnline(now()->subSeconds(121)))->toBeFalse();

    $inactive = User::factory()->inactive()->create(['last_seen_at' => now()]);
    expect(PresenceService::present($inactive)['online'])->toBeFalse();
});

test('no endpoint exposes presence to users without chat access', function () {
    $this->org->priya->forceFill(['last_seen_at' => now()])->save();
    setPermission($this->org->rahul, 'chat.use', 'deny');

    $this->actingAs($this->org->rahul)->getJson('/chat/users')->assertForbidden();
    $heartbeat = $this->actingAs($this->org->rahul)->postJson('/presence/heartbeat')->assertOk();

    expect($heartbeat->json('chat_unread'))->toBe(0)
        ->and(array_keys($heartbeat->json()))->toEqualCanonicalizing(['chat_unread', 'priority']);
});
