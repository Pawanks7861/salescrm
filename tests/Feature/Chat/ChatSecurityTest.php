<?php

use App\Models\Attachment;
use App\Models\Lead;
use App\Services\AuditService;
use App\Support\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->org = salesOrg();
    $this->conversation = chatBetween($this->org->rahul, $this->org->priya);
    $this->message = chatSend($this->conversation, $this->org->rahul, 'Private note', [UploadedFile::fake()->create('plan.pdf', 5, 'application/pdf')]);
    $this->attachment = $this->message->attachments->sole();
});

test('non-participants — including Admin and Super Admin — cannot read, send to, mark or type in a conversation', function () {
    $id = $this->conversation->id;

    foreach ([$this->org->outsider, $this->org->manager, $this->org->admin, $this->org->super] as $user) {
        $this->actingAs($user)->get("/chat/{$id}")->assertForbidden();
        $this->actingAs($user)->getJson("/chat/conversations/{$id}/messages")->assertForbidden();
        $this->actingAs($user)->postJson("/chat/conversations/{$id}/messages", ['message' => 'intrude'])->assertForbidden();
        $this->actingAs($user)->postJson("/chat/conversations/{$id}/read")->assertForbidden();
        $this->actingAs($user)->postJson("/chat/conversations/{$id}/typing")->assertForbidden();
    }

    expect($this->conversation->messages()->count())->toBe(1);
});

test('the conversation list of an admin never includes other people\'s conversations', function () {
    foreach ([$this->org->admin, $this->org->super] as $user) {
        $response = $this->actingAs($user)->getJson('/chat/conversations')->assertOk();
        expect($response->json('conversations'))->toBe([])
            ->and($response->getContent())->not->toContain('Private note');
    }
});

test('attachment downloads require participation and return 404 otherwise', function () {
    $this->actingAs($this->org->priya)->get("/chat/attachments/{$this->attachment->id}")
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'attachment; filename=plan.pdf');

    foreach ([$this->org->outsider, $this->org->admin, $this->org->super] as $user) {
        $this->actingAs($user)->get("/chat/attachments/{$this->attachment->id}")->assertNotFound();
    }
    $this->actingAs($this->org->priya)->get('/chat/attachments/999999')->assertNotFound();
});

test('lead attachments cannot be fetched through the chat download route', function () {
    $lead = Lead::factory()->create(['assigned_to' => $this->org->rahul->id]);
    $leadFile = new Attachment;
    $leadFile->forceFill([
        'attachable_type' => $lead->getMorphClass(), 'attachable_id' => $lead->id,
        'original_name' => 'contract.pdf', 'stored_name' => 'x.pdf', 'disk' => 'local', 'path' => 'leads/x.pdf',
        'mime_type' => 'application/pdf', 'size' => 10, 'uploaded_by' => $this->org->rahul->id,
    ])->save();

    $this->actingAs($this->org->rahul)->get("/chat/attachments/{$leadFile->id}")->assertNotFound();
});

test('non-image attachments are never served inline', function () {
    $this->actingAs($this->org->priya)->get("/chat/attachments/{$this->attachment->id}?inline=1")
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=plan.pdf');
});

test('users without chat.use cannot download chat files even as a participant', function () {
    setPermission($this->org->priya, Permissions::CHAT_USE, 'deny');

    $this->actingAs($this->org->priya)->get("/chat/attachments/{$this->attachment->id}")->assertForbidden();
});

test('guests are redirected to login for every chat endpoint', function () {
    $this->get('/chat')->assertRedirect('/login');
    $this->getJson("/chat/conversations/{$this->conversation->id}/messages")->assertUnauthorized();
    $this->get("/chat/attachments/{$this->attachment->id}")->assertRedirect('/login');
    $this->postJson('/presence/heartbeat')->assertUnauthorized();
});

test('inactive users are blocked from chat', function () {
    $this->org->priya->forceFill(['is_active' => false])->save();

    $this->actingAs($this->org->priya)->get('/chat')->assertRedirect();
    expect($this->actingAs($this->org->priya)->getJson("/chat/conversations/{$this->conversation->id}/messages")->status())->not->toBe(200);
});

test('private chat content is never written to the audit log', function () {
    $spy = $this->spy(AuditService::class);
    $this->actingAs($this->org->rahul)->postJson("/chat/conversations/{$this->conversation->id}/messages", ['message' => 'Confidential salary talk'])->assertCreated();

    $spy->shouldNotHaveReceived('log');
    expect(DB::table('audit_logs')->where('new_values_json', 'like', '%Confidential%')->orWhere('description', 'like', '%Confidential%')->exists())->toBeFalse();
});

test('message and attachment JSON never exposes storage internals or user secrets', function () {
    $json = $this->actingAs($this->org->priya)->getJson("/chat/conversations/{$this->conversation->id}/messages")->getContent();

    expect($json)->not->toContain($this->attachment->stored_name)
        ->not->toContain('"disk"')
        ->not->toContain('"path"')
        ->not->toContain('password')
        ->not->toContain($this->org->rahul->email);
});

test('ids from another conversation cannot be used to edit or delete', function () {
    $foreign = chatSend(chatBetween($this->org->manager, $this->org->outsider), $this->org->manager, 'Theirs');

    $this->actingAs($this->org->rahul)->putJson("/chat/messages/{$foreign->id}", ['message' => 'x'])->assertForbidden();
    $this->actingAs($this->org->rahul)->deleteJson("/chat/messages/{$foreign->id}")->assertForbidden();
    expect($foreign->fresh()->body)->toBe('Theirs');
});
